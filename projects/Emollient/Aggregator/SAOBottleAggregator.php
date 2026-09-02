<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AbstractAggregator;

/**
 * SAOBottleAggregator
 *
 * Site-wise SAO bottle supply summary at discharge.
 *
 * N = babies where dis_bottles_required > 0
 *     (babies who never needed bottles are excluded from all counts)
 *
 * Per site:
 *   bottles_required  = sum of dis_bottles_required
 *   bottles_given     = sum of dis_bottles_given
 *   coverage_pct      = bottles_given / bottles_required × 100  (bottle-level rate)
 *   babies_n          = count where dis_bottles_required > 0
 *   babies_full       = count where dis_bottles_given >= dis_bottles_required
 *   babies_full_pct   = babies_full / babies_n × 100            (baby-level rate)
 */
class SAOBottleAggregator extends AbstractAggregator
{
    private string     $primaryKey;
    private array      $siteFilter;
    private ?\DateTime $from;
    private ?\DateTime $to;

    // Per-record accumulators
    private array $site        = [];  // record_id => enr_hosp_code
    private array $arm         = [];  // record_id => enr_study_arm
    private array $enrolled    = [];  // record_id => bool
    private array $enrDate     = [];  // record_id => DateTime (enr_datetime)
    private array $disRequired = [];  // record_id => float (dis_bottles_required)
    private array $disGiven    = [];  // record_id => float (dis_bottles_given)

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

            // ── Enrollment — day0_arm_1 non-repeating ─────────────────────
            if ($event === 'day0_arm_1' && $repeatForm === '')
            {
                $consent = trim($row['enr_consent_granted'] ?? '');
                $hosp    = trim($row['enr_hosp_code']       ?? '');
                $arm     = trim($row['enr_study_arm']        ?? '');

                if (($consent === 'Y' || $consent === '1') && $hosp !== '') {
                    $this->enrolled[$id] = true;
                    $this->site[$id]     = $hosp;
                    $this->arm[$id]      = $arm;
                }

                if (!empty($row['enr_datetime']))
                    $this->enrDate[$id] = $this->parseDate($row['enr_datetime']);
            }

            // ── Discharge — dis_bottles_required / given ───────────────────
            if ($event === 'discharge_arm_1')
            {
                $req = $row['dis_bottles_required'] ?? '';
                $giv = $row['dis_bottles_given']    ?? '';

                if (is_numeric($req) && (float)$req > 0)
                    $this->disRequired[$id] = (float)$req;
                if (is_numeric($giv))
                    $this->disGiven[$id] = (float)$giv;
            }
        }

        return $this->resolve();
    }

    private function resolve(): array
    {
        $babies = [];
        $bySite = [];

        foreach ($this->enrolled as $id => $__)
        {
            $site = $this->site[$id] ?? null;
            if ($site === null) continue;

            // Site filter
            if (!empty($this->siteFilter)
                && !in_array($site, $this->siteFilter, true)) continue;

            // Date filter on enr_datetime
            $enrDate = $this->enrDate[$id] ?? null;
            if ($this->from || $this->to) {
                if ($enrDate === null) continue;
                if ($this->from && $enrDate < $this->from) continue;
                if ($this->to   && $enrDate > $this->to)   continue;
            }

            $required = $this->disRequired[$id] ?? null;
            $given    = $this->disGiven[$id]    ?? null;

            // Only include babies who needed bottles
            if ($required === null || $required <= 0) continue;

            $fullySupplied = ($given !== null && $given >= $required);

            $babies[$id] = [
                'record_id'      => $id,
                'site'           => $site,
                'arm'            => $this->arm[$id] ?? '',
                'required'       => $required,
                'given'          => $given ?? 0,
                'fully_supplied' => $fullySupplied,
            ];

            // Accumulate into site buckets
            foreach ([$site, 'Total'] as $grp) {
                if (!isset($bySite[$grp]))
                    $bySite[$grp] = [
                        'site'             => $grp,
                        'babies_n'         => 0,
                        'bottles_required' => 0,
                        'bottles_given'    => 0,
                        'babies_full'      => 0,
                    ];
                $bySite[$grp]['babies_n']++;
                $bySite[$grp]['bottles_required'] += $required;
                $bySite[$grp]['bottles_given']    += ($given ?? 0);
                if ($fullySupplied) $bySite[$grp]['babies_full']++;
            }
        }

        // Percentages
        foreach ($bySite as &$s) {
            $s['coverage_pct']      = $s['bottles_required'] > 0
                ? round($s['bottles_given']  / $s['bottles_required'] * 100, 1)
                : null;
            $s['babies_full_pct']   = $s['babies_n'] > 0
                ? round($s['babies_full']    / $s['babies_n']          * 100, 1)
                : null;
        }
        unset($s);

        // Move Total to end
        if (isset($bySite['Total'])) {
            $t = $bySite['Total']; unset($bySite['Total']); $bySite['Total'] = $t;
        }

        return ['by_site' => $bySite, 'babies' => $babies];
    }

    private function parseDate(string $raw): ?\DateTime
    {
        if (empty($raw)) return null;
        try { $dt = new \DateTime($raw); $dt->setTime(0,0,0); return $dt; }
        catch (\Exception) { return null; }
    }
}
