<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AbstractAggregator;
use CEL\Projects\Emollient\Support\SiteResolver;

/**
 * DataCollectorAggregator
 *
 * PreScreening DC performance report — Phase 1.
 *
 * Produces:
 *   by_dc        — exclusion metric counts per DC
 *   metric_meta  — metric display metadata
 *   dc_list      — DC list with site and total
 *   wt_hist      — birth weight histogram per DC
 *   wt_bins      — ordered bin labels
 *   wt_bin       — configured bin size
 *   field_freq   — field value frequency per DC (for data quality analysis)
 *   completion   — field completion rate per DC
 */
class DataCollectorAggregator extends AbstractAggregator
{
    private string     $primaryKey;
    private array        $siteFilter;
    private SiteResolver $siteResolver;
    private ?\DateTime $from;
    private ?\DateTime $to;
    private int        $wtBin;

    private const PRESCREENING_METRICS = [
        'babies_prescreened',
        'baby_admit_nicu',
        'birth_weight_no',
        'baby_current_status',
        'baby_admitted_nicu_24hrs',
        'baby_mech_venti',
        'baby_other_study',
        'baby_prescrn_eligible',
    ];

    private const WT_MIN = 500;
    private const WT_MAX = 1800;

    /**
     * Categorical fields to analyse for value frequency distribution.
     * Format: field_name => display label
     * Only fields present in the REDCap stream are included.
     */
    private const FREQ_FIELDS = [
        'baby_admit_nicu'        => 'Admitted to NICU',
        'baby_inclusion_brth_wt' => 'Birth weight eligible (700-1800g)',
        'baby_current_status'    => 'Current status at PreScreening',
        'baby_admitted_nicu_24hrs'=> 'NICU admission within 24hrs',
        'baby_mech_venti_1'      => 'On ventilator (first check)',
        'baby_prescrn_eligible'  => 'PreScreening eligible (To be Screened)',
    ];

    /**
     * All fields expected to be filled on every prescreening row.
     * Used for completion rate calculation.
     */
    private const EXPECTED_FIELDS = [
        'baby_admit_nicu',
        'baby_inclusion_brth_wt',
        'baby_current_status',
        'baby_admitted_nicu_24hrs',
        'baby_mech_venti_1',
        'baby_prescrn_eligible',
        'baby_weight_nicu',
    ];

    public function __construct(
        string  $primaryKey,
        array   $siteFilter = [],
        ?string $dateFrom   = null,
        ?string $dateTo     = null,
        int     $wtBin      = 50
    ) 
    {
        $this->primaryKey = $primaryKey;
        $this->siteFilter   = $siteFilter;
        $this->siteResolver = new SiteResolver();
        $this->from  = $dateFrom ? new \DateTime($dateFrom) : null;
        $this->to    = $dateTo   ? (new \DateTime($dateTo))->setTime(23, 59, 59) : null;
        $this->wtBin = max(1, $wtBin);
    }

    public function aggregate(iterable $records): array
    {
        $allMetrics    = EligibilityMetricDefinitions::metrics();
        $allMetricMeta = EligibilityMetricDefinitions::metricMeta();
        $metrics    = array_intersect_key($allMetrics,    array_flip(self::PRESCREENING_METRICS));
        $metricMeta = array_intersect_key($allMetricMeta, array_flip(self::PRESCREENING_METRICS));

        $byDc      = [];   // metric => [ dc_id => count ]
        $dcList    = [];   // dc_id  => [ site, total ]
        $wtHist    = [];   // dc_id  => [ bin_label => count ]
        $fieldFreq = [];   // dc_id  => [ field => [ value => count ] ]
        $completion= [];   // dc_id  => [ field => [ filled => int, total => int ] ]

        foreach ($records as $row)
        {
            $id = $row[$this->primaryKey] ?? null;
            if (!$id) continue;

            $hosp = $this->siteResolver->resolve($id, $row, $event, $repeatForm);
            $dc   = trim($row['baby_dc_id']     ?? '');

            // Date filter on baby_datetime_birth
            if ($this->from || $this->to) 
            {
                $raw = $row['baby_datetime_birth'] ?? '';
                if (!empty($raw)) 
                {
                    try   { $dt = new \DateTime($raw); }
                    catch (\Exception) { continue; }
                    if ($this->from && $dt < $this->from) continue;
                    if ($this->to   && $dt > $this->to)   continue;
                }
            }

            // Site filter
            if (!empty($this->siteFilter) && $hosp !== '') 
            {
                if (!in_array($hosp, $this->siteFilter, true)) continue;
            }

            // PreScreening rows only (repeating instances)
            $repeatInst = (int)($row['redcap_repeat_instance'] ?? 0);
            if ($repeatInst < 1) continue;

            $dcKey = $dc !== '' ? $dc : 'Unknown';

            // Track DC
            if (!isset($dcList[$dcKey])) 
            {
                $dcList[$dcKey] = ['site' => $hosp, 'total' => 0];
            }
            $dcList[$dcKey]['total']++;

            // ── Exclusion metrics ─────────────────────────────────────────
            foreach ($metrics as $metric => $condition) 
            {
                $byDc[$metric][$dcKey]  ??= 0;
                $byDc[$metric]['Total'] ??= 0;
                if ($condition($row)) {
                    $byDc[$metric][$dcKey]++;
                    $byDc[$metric]['Total']++;
                }
            }

            // ── Birth weight histogram ────────────────────────────────────
            $wtRaw = trim($row['baby_weight_nicu'] ?? '');
            if ($wtRaw !== '' && is_numeric($wtRaw)) 
            {
                $wt = (float)$wtRaw;
                if ($wt >= self::WT_MIN && $wt <= self::WT_MAX) {
                    $binStart = (int)(floor($wt / $this->wtBin) * $this->wtBin);
                    $binLabel = "{$binStart}-" . ($binStart + $this->wtBin - 1);
                    $wtHist[$dcKey][$binLabel] ??= 0;
                    $wtHist[$dcKey][$binLabel]++;
                }
            }

            // ── Field value frequency ─────────────────────────────────────
            // Records how often each value appears per field per DC.
            // Blank values are recorded as '(blank)' so we can see
            // whether a DC leaves fields empty more than peers.
            foreach (self::FREQ_FIELDS as $field => $label) 
            {
                $val = isset($row[$field]) ? trim($row[$field]) : null;
                if ($val === null) continue;   // field not in stream at all
                $valKey = ($val === '') ? '(blank)' : $val;
                $fieldFreq[$dcKey][$field][$valKey]   ??= 0;
                $fieldFreq[$dcKey][$field][$valKey]++;
                $fieldFreq['Total'][$field][$valKey]  ??= 0;
                $fieldFreq['Total'][$field][$valKey]++;
            }

            // ── Completion rate ───────────────────────────────────────────
            // Counts how many expected fields are filled (non-blank) per DC.
            foreach (self::EXPECTED_FIELDS as $field) 
            {
                $val = isset($row[$field]) ? trim($row[$field]) : null;
                $completion[$dcKey][$field]['total']  = ($completion[$dcKey][$field]['total']  ?? 0) + 1;
                $completion[$dcKey][$field]['filled']  = ($completion[$dcKey][$field]['filled']  ?? 0)
                    + (($val !== null && $val !== '') ? 1 : 0);
                $completion['Total'][$field]['total']  = ($completion['Total'][$field]['total']  ?? 0) + 1;
                $completion['Total'][$field]['filled'] = ($completion['Total'][$field]['filled'] ?? 0)
                    + (($val !== null && $val !== '') ? 1 : 0);
            }
        }

        // Sort DCs by site then code
        uksort($dcList, fn($a, $b) =>
            [$dcList[$a]['site'], $a] <=> [$dcList[$b]['site'], $b]
        );

        // Build ordered bin labels
        $wtBins = [];
        for ($w = self::WT_MIN; $w < self::WT_MAX; $w += $this->wtBin) 
        {
            $wtBins[] = "{$w}-" . ($w + $this->wtBin - 1);
        }

        return [
            'by_dc'       => $byDc,
            'metric_meta' => $metricMeta,
            'dc_list'     => $dcList,
            'wt_hist'     => $wtHist,
            'wt_bins'     => $wtBins,
            'wt_bin'      => $this->wtBin,
            'field_freq'  => $fieldFreq,
            'freq_fields' => self::FREQ_FIELDS,
            'completion'  => $completion,
            'expected_fields' => self::EXPECTED_FIELDS,
        ];
    }
}
