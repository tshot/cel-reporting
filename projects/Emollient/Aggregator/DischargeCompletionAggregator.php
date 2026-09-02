<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AbstractAggregator;

/**
 * DischargeCompletionAggregator
 *
 * Tracks discharge form completion with:
 *   - N = enrolled babies (enr_consent_granted = Y)
 *   - Discharge form completion status
 *   - Discharge type breakdown (dis_discharge_type)
 *   - Special discharge types: TYP_FP, TYP_DEA, TYP_REF
 *     % of special types uses enrolled babies as N
 *
 * Output:
 * [
 *   'by_site' => [
 *     site => [
 *       'enrolled'       => int,
 *       'form_complete'  => int,   // discharge_form_complete = '2'
 *       'form_missing'   => int,
 *       'form_pct'       => float,
 *       'type_counts'    => [ 'TYP_FP'=>int, 'TYP_DEA'=>int, 'TYP_REF'=>int, ... ]
 *       'special_count'  => int,   // TYP_FP + TYP_DEA + TYP_REF
 *       'special_pct'    => float, // special_count / enrolled × 100
 *     ],
 *     'Total' => [...]
 *   ],
 *   'participants' => [
 *     record_id => [
 *       'record_id'      => string,
 *       'site'           => string,
 *       'arm'            => string,
 *       'form_complete'  => bool,
 *       'discharge_type' => string,
 *       'is_special'     => bool,
 *     ]
 *   ]
 * ]
 */
class DischargeCompletionAggregator extends AbstractAggregator
{
    private string     $primaryKey;
    private array      $siteFilter;
    private ?\DateTime $from;
    private ?\DateTime $to;

    private const SPECIAL_TYPES = ['TYP_FP', 'TYP_DEA', 'TYP_REF'];

    // Per-record accumulators
    private array $site         = [];  // record_id => enr_hosp_code
    private array $arm          = [];  // record_id => enr_study_arm
    private array $enrolled     = [];  // record_id => true
    private array $enrDate      = [];  // record_id => DateTime
    private array $formComplete = [];  // record_id => bool
    private array $disType      = [];  // record_id => string

    public function __construct(
        string  $primaryKey,
        array   $siteFilter = [],
        ?string $dateFrom   = null,
        ?string $dateTo     = null
    ) {
        $this->primaryKey = $primaryKey;
        $this->siteFilter = array_map('trim', $siteFilter);
        $this->from = $dateFrom ? (new \DateTime($dateFrom))->setTime(0,  0,  0) : null;
        $this->to   = $dateTo   ? (new \DateTime($dateTo  ))->setTime(23, 59, 59) : null;
    }

    public function aggregate(iterable $records): array
    {
        foreach ($records as $row)
        {
            $id = $row[$this->primaryKey] ?? null;
            if (!$id) continue;

            $event      = $row['redcap_event_name']        ?? '';
            $repeatForm = $row['redcap_repeat_instrument'] ?? '';

            // ── day0_arm_1 non-repeating — enrollment ──────────────────────
            if ($event === 'day0_arm_1' && $repeatForm === '')
            {
                $consent = trim($row['enr_consent_granted'] ?? '');
                $hosp    = trim($row['enr_hosp_code']       ?? '');
                $arm     = trim($row['enr_study_arm']       ?? '');

                if (($consent === 'Y' || $consent === '1') && $hosp !== '') {
                    $this->enrolled[$id] = true;
                    $this->site[$id]     = $hosp;
                    $this->arm[$id]      = $arm;
                }
                if (!empty($row['enr_datetime']))
                    $this->enrDate[$id] = $this->parseDate($row['enr_datetime']);
            }

            // ── discharge_arm_1 ────────────────────────────────────────────
            if ($event === 'discharge_arm_1')
            {
                $this->formComplete[$id] = ($row['discharge_form_complete'] ?? '') === '2';

                $dtype = trim($row['dis_discharge_type'] ?? '');
                if ($dtype !== '') $this->disType[$id] = $dtype;
            }
        }

        return $this->resolve();
    }

    private function resolve(): array
    {
        $participants = [];
        $bySite       = [];

        // All distinct discharge types seen (for dynamic columns)
        $allTypes = [];

        foreach ($this->enrolled as $id => $__)
        {
            $site = $this->site[$id] ?? null;
            if ($site === null) continue;

            // Site filter
            if (!empty($this->siteFilter)
                && !in_array($site, $this->siteFilter, true)) continue;

            // Date filter
            $enrDate = $this->enrDate[$id] ?? null;
            if ($this->from || $this->to) {
                if ($enrDate === null) continue;
                if ($this->from && $enrDate < $this->from) continue;
                if ($this->to   && $enrDate > $this->to)   continue;
            }

            $formDone = $this->formComplete[$id] ?? false;
            $dtype    = $this->disType[$id]      ?? '';
            $isSpec   = in_array($dtype, self::SPECIAL_TYPES, true);

            if ($dtype !== '') $allTypes[$dtype] = true;

            $participants[$id] = [
                'record_id'      => $id,
                'site'           => $site,
                'arm'            => $this->arm[$id] ?? '',
                'form_complete'  => $formDone,
                'discharge_type' => $dtype,
                'is_special'     => $isSpec,
            ];

            foreach ([$site, 'Total'] as $grp) {
                if (!isset($bySite[$grp]))
                    $bySite[$grp] = [
                        'enrolled'      => 0,
                        'form_complete' => 0,
                        'type_counts'   => [],
                        'special_count' => 0,
                    ];
                $bySite[$grp]['enrolled']++;
                if ($formDone)   $bySite[$grp]['form_complete']++;
                if ($dtype !== '') {
                    $bySite[$grp]['type_counts'][$dtype] =
                        ($bySite[$grp]['type_counts'][$dtype] ?? 0) + 1;
                }
                if ($isSpec) $bySite[$grp]['special_count']++;
            }
        }

        // Percentages
        foreach ($bySite as &$s) {
            $n = $s['enrolled'];
            $s['form_missing']  = $n - $s['form_complete'];
            $s['form_pct']      = $n > 0 ? round($s['form_complete']  / $n * 100, 1) : null;
            $s['special_pct']   = $n > 0 ? round($s['special_count']  / $n * 100, 1) : null;
        }
        unset($s);

        // Move Total to end
        if (isset($bySite['Total'])) {
            $t = $bySite['Total']; unset($bySite['Total']); $bySite['Total'] = $t;
        }

        return [
            'by_site'       => $bySite,
            'participants'  => $participants,
            'all_types'     => array_keys($allTypes),
            'special_types' => self::SPECIAL_TYPES,
            'period'        => [
                'date_from' => $this->from ? $this->from->format('Y-m-d') : null,
                'date_to'   => $this->to   ? $this->to->format('Y-m-d')   : null,
            ],
        ];
    }

    private function parseDate(string $raw): ?\DateTime
    {
        if (empty($raw)) return null;
        try { $dt = new \DateTime($raw); $dt->setTime(0,0,0); return $dt; }
        catch (\Exception) { return null; }
    }
}
