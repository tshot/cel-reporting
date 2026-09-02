<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AbstractAggregator;

/**
 * LengthOfStayAggregator
 *
 * Computes Length of Stay (LOS) in days for every enrolled baby.
 *
 * Data sources (both fetched in a single REDCap stream):
 *
 *   discharge_arm_1  →  dis_hosp_code      (site)
 *                       dis_study_arm      (Intervention / Control)
 *                       dis_in_hosp        ("Yes" = still in hospital at Day 28)
 *                       dis_datetime       (actual discharge datetime, when dis_in_hosp = "N")
 *
 *   day0_arm_1       →  baby_eligible_enroll   ("Yes" identifies the enrolled baby
 *                                               among twins/triplets on the same record)
 *                       baby_datetime_admission (admission date for that baby)
 *
 * Business rules:
 *   dis_in_hosp = "Y"  →  LOS = 28 (fixed — still in hospital at Day 28)
 *   dis_in_hosp = "N"  →  LOS = floor((dis_datetime − baby_datetime_admission) / 86400)
 *   Admission or discharge data missing → LOS = null (excluded from stats)
 *   Discharge before admission (data entry error) → LOS = null
 *
 * Output shape:
 * [
 *   'patients'        => [                    // all enrolled babies
 *     record_id => [
 *       'site'           => string,
 *       'study_arm'      => string,
 *       'admission_date' => 'Y-m-d H:i:s' | null,
 *       'discharge_date' => 'Y-m-d H:i:s' | null,
 *       'in_hosp_day28'  => bool | null,
 *       'los_days'       => int | null,
 *     ],
 *   ],
 *   'by_site'         => [                    // hospital-wise summary
 *     site_code => LOS_STATS,
 *     'Total'   => LOS_STATS,
 *   ],
 *   'by_arm'          => [                    // arm-wise summary
 *     'Intervention' => LOS_STATS,
 *     'Control'      => LOS_STATS,
 *     'Total'        => LOS_STATS,
 *   ],
 *   'distribution'    => [                    // LOS band counts for histogram / stacked bar
 *     site_code => ['lt7'=>N, 'w7_14'=>N, 'w15_27'=>N, 'day28'=>N, 'unknown'=>N],
 *     'Total'   => [...],
 *   ],
 *   'period'          => [...],               // injected by engine
 * ]
 *
 * Where LOS_STATS is:
 * [
 *   'count'            => int,    // patients with computable LOS
 *   'count_day28'      => int,    // patients still in hospital at Day 28
 *   'count_missing'    => int,    // patients with insufficient data
 *   // With Day-28 cases included (LOS=28 counted at face value):
 *   'mean_incl'        => float|null,
 *   'median_incl'      => float|null,
 *   'std_incl'         => float|null,
 *   'min_incl'         => int|null,
 *   'max_incl'         => int|null,
 *   // Without Day-28 cases (discharged babies only):
 *   'count_excl'       => int,
 *   'mean_excl'        => float|null,
 *   'median_excl'      => float|null,
 *   'std_excl'         => float|null,
 *   'min_excl'         => int|null,
 *   'max_excl'         => int|null,
 *   'pct_day28'        => float|null,   // % of total with LOS data
 * ]
 */
class LengthOfStayAggregator extends AbstractAggregator
{
    private string $primaryKey;
    private array  $siteFilter;    // empty = all sites

    // ── Per-record buckets populated during stream ─────────────────────────
    // Keyed by record_id. All nullable — a record may arrive without data
    // on one or both events (e.g. not yet discharged).
    private array $admissionDate = [];   // record_id => \DateTime
    private array $site          = [];   // record_id => string  (from dis_hosp_code)
    private array $studyArm      = [];   // record_id => string  (from dis_study_arm)
    private array $inHospDay28   = [];   // record_id => bool
    private array $disDatetime   = [];   // record_id => \DateTime  (only when dis_in_hosp=N)

    public function __construct(
        string $primaryKey,
        array  $siteFilter = []
    ) {
        $this->primaryKey = $primaryKey;
        $this->siteFilter = array_map('trim', $siteFilter);
    }

    // =========================================================================
    // AggregatorInterface
    // =========================================================================

    public function aggregate(iterable $records): array
    {
        foreach ($records as $row)
        {
            $id = $row[$this->primaryKey] ?? null;
            if (!$id) continue;

            $event = $row['redcap_event_name'] ?? '';

            if ($event === 'day0_arm_1')       $this->collectScreeningRow($id, $row);
            if ($event === 'discharge_arm_1')  $this->collectDischargeRow($id, $row);
        }

        return $this->resolve();
    }

    // =========================================================================
    // Stream collection helpers
    // =========================================================================

    /**
     * Collect admission date from the screening form.
     * Only the row where baby_eligible_enroll = "Yes" is accepted —
     * this identifies the enrolled baby when twins/triplets share a record_id.
     */
    private function collectScreeningRow(string $id, array $row): void
    {
        if (($row['baby_eligible_enroll'] ?? '') !== 'Yes') return;

        $raw = trim($row['baby_datetime_admission'] ?? '');
        if ($raw === '') return;

        try {
            $this->admissionDate[$id] = new \DateTime($raw);
        } catch (\Exception) {
            // Malformed datetime — leave null, will surface as missing data
        }
    }

    /**
     * Collect discharge data. Site and study arm come from the discharge form
     * (dis_hosp_code, dis_study_arm) because only enrolled babies have a
     * discharge record — no need to cross-reference the enrollment form.
     */
    private function collectDischargeRow(string $id, array $row): void
    {
        // Site is mandatory — a discharge row without a site code is unusable.
        // This guards against partially saved REDCap records where dis_hosp_code
        // has not yet been filled in.
        $site = trim($row['dis_hosp_code'] ?? '');
        if ($site === '') return;

        $arm = trim($row['dis_study_arm'] ?? '');
        $this->site[$id]     = $site;
        if ($arm !== '') $this->studyArm[$id] = $arm;

        // Discharge status
        $inHosp = trim($row['dis_in_hosp'] ?? '');
        if ($inHosp === '') return;   // discharge form present but dis_in_hosp blank — treat as not yet answered

        $this->inHospDay28[$id] = ($inHosp === 'Y');

        // Discharge datetime — only meaningful when dis_in_hosp = "No"
        if (!$this->inHospDay28[$id])
        {
            $raw = trim($row['dis_datetime'] ?? '');
            if ($raw !== '')
            {
                try 
                {
                    $this->disDatetime[$id] = new \DateTime($raw);
                } 
                catch (\Exception) 
                {
                    // Malformed — leave null
                }
            }
        }
    }

    // =========================================================================
    // Resolution — compute LOS and build all output structures
    // =========================================================================

    private function resolve(): array
    {
        $patients     = [];
        $bySiteValues = [];    // site => ['incl' => [LOS,...], 'excl' => [LOS,...], 'day28'=>N, 'missing'=>N]
        $byArmValues  = [];    // arm  => same shape
        $distribution = [];    // site => ['lt7'=>N, 'w7_14'=>N, 'w15_27'=>N, 'day28'=>N, 'unknown'=>N]

        // Union of all record IDs seen across both events
        $allIds = array_unique(array_merge(
            array_keys($this->admissionDate),
            array_keys($this->inHospDay28)
        ));

        foreach ($allIds as $id)
        {
            $site        = $this->site[$id]          ?? null;
            $arm         = $this->studyArm[$id]      ?? null;
            $admission   = $this->admissionDate[$id] ?? null;
            $inHospDay28 = $this->inHospDay28[$id]   ?? null;
            $discharge   = $this->disDatetime[$id]   ?? null;

            // Skip records where the discharge form had no site code —
            // collectDischargeRow() guards this, but records seen only on
            // day0_arm_1 (not yet discharged) also have no site; exclude them.
            if ($site === null) continue;

            // Apply site filter
            if (!empty($this->siteFilter) && !in_array($site, $this->siteFilter, true))
            {
                continue;
            }

            $arm     = $arm ?? '';
            $losDays = $this->computeLos($admission, $inHospDay28, $discharge);

            $patients[$id] = [
                'site'           => $site,
                'study_arm'      => $arm,
                'admission_date' => $admission?->format('Y-m-d H:i:s'),
                'discharge_date' => $discharge?->format('Y-m-d H:i:s'),
                'in_hosp_day28'  => $inHospDay28,
                'los_days'       => $losDays,
            ];

            // Accumulate into site and arm buckets
            foreach ([$site, 'Total'] as $grp)
            {
                $this->accumulateValue($bySiteValues[$grp], $losDays, $inHospDay28);
                $this->accumulateBand($distribution[$grp], $losDays, $inHospDay28);
            }

            foreach ([$arm, 'Total'] as $grp)
            {
                $this->accumulateValue($byArmValues[$grp], $losDays, $inHospDay28);
            }
        }

        // Build summary stats from raw value buckets
        $bySite = [];
        foreach ($bySiteValues as $grp => $bucket)
        {
            $bySite[$grp] = $this->buildStats($bucket);
        }

        $byArm = [];
        foreach ($byArmValues as $grp => $bucket)
        {
            $byArm[$grp] = $this->buildStats($bucket);
        }

        // Ensure Total is last in each summary
        foreach ([$bySite, $byArm, $distribution] as &$arr)
        {
            if (isset($arr['Total']))
            {
                $total = $arr['Total'];
                unset($arr['Total']);
                $arr['Total'] = $total;
            }
        }
        unset($arr);

        return [
            'patients'     => $patients,
            'by_site'      => $bySite,
            'by_arm'       => $byArm,
            'distribution' => $distribution,
        ];
    }

    // =========================================================================
    // LOS computation — core business rule
    // =========================================================================

    /**
     * Returns LOS in whole days, or null when data is insufficient.
     *
     *   inHospDay28 = null          → discharge form not yet submitted → null
     *   inHospDay28 = true          → LOS = 28 (fixed)
     *   inHospDay28 = false         → LOS = floor(discharge − admission in seconds / 86400) → Completed Days of Stay. Partial not counted
     *   Missing admission            → null
     *   Missing dis_datetime         → null
     *   discharge < admission        → null  (data entry error)
     */
    private function computeLos(
        ?\DateTime $admission,
        ?bool      $inHospDay28,
        ?\DateTime $discharge
    ): ?int {
        if ($inHospDay28 === null)  return null;   // discharge form not submitted
        if ($inHospDay28 === true)  return 28;     // Day-28 fixed value

        // Discharged — both dates required
        if ($admission === null || $discharge === null) return null;

        // Guard against data entry errors
        if ($discharge < $admission) return null;

        // A partial day counts as a full day (ceil)
        $diffSeconds = $discharge->getTimestamp() - $admission->getTimestamp();
        
        return (int) floor($diffSeconds / 86400);
    }

    // =========================================================================
    // Accumulation helpers
    // =========================================================================

    /**
     * Accumulate a single LOS value into a summary bucket (by reference).
     * Bucket shape: ['incl' => [], 'excl' => [], 'day28' => N, 'missing' => N]
     */
    private function accumulateValue(mixed &$bucket, ?int $los, ?bool $inHospDay28): void
    {
        $bucket ??= ['incl' => [], 'excl' => [], 'day28' => 0, 'missing' => 0];

        if ($los === null)
        {
            $bucket['missing']++;
            return;
        }

        $bucket['incl'][] = $los;   // all computable LOS values (28 included)

        if ($inHospDay28 === true)
        {
            $bucket['day28']++;     // count of Day-28 cases
        }
        else
        {
            $bucket['excl'][] = $los;   // discharged-only values
        }
    }

    /**
     * Accumulate LOS band counts for the stacked-bar distribution.
     * Bucket shape: ['lt7'=>N, 'w7_14'=>N, 'w15_27'=>N, 'day28'=>N, 'unknown'=>N]
     */
    private function accumulateBand(mixed &$bucket, ?int $los, ?bool $inHospDay28): void
    {
        $bucket ??= ['lt7' => 0, 'w7_14' => 0, 'w15_27' => 0, 'day28' => 0, 'unknown' => 0];

        if ($los === null)                   { $bucket['unknown']++; return; }
        if ($inHospDay28 === true)           { $bucket['day28']++;   return; }
        if ($los < 7)                        { $bucket['lt7']++;     return; }
        if ($los >= 7  && $los <= 14)        { $bucket['w7_14']++;   return; }
        if ($los >= 15 && $los <= 27)        { $bucket['w15_27']++;  return; }

        // Shouldn't reach here (LOS=28 already caught above), but be safe
        $bucket['unknown']++;
    }

    // =========================================================================
    // Stats builder
    // =========================================================================

    /**
     * Build the full LOS_STATS array from an accumulated bucket.
     */
    private function buildStats(array $bucket): array
    {
        $incl    = $bucket['incl'];
        $excl    = $bucket['excl'];
        $nDay28  = $bucket['day28'];
        $missing = $bucket['missing'];
        $nTotal  = count($incl);

        return [
            'count'         => $nTotal,
            'count_day28'   => $nDay28,
            'count_missing' => $missing,
            'pct_day28'     => $nTotal > 0 ? round(($nDay28 / $nTotal) * 100, 1) : null,

            // With Day-28 cases (LOS=28 counted at face value)
            'mean_incl'     => $this->mean($incl),
            'median_incl'   => $this->median($incl),
            'std_incl'      => $this->std($incl),
            'min_incl'      => count($incl) ? min($incl) : null,
            'max_incl'      => count($incl) ? max($incl) : null,

            // Without Day-28 cases (discharged babies only)
            'count_excl'    => count($excl),
            'mean_excl'     => $this->mean($excl),
            'median_excl'   => $this->median($excl),
            'std_excl'      => $this->std($excl),
            'min_excl'      => count($excl) ? min($excl) : null,
            'max_excl'      => count($excl) ? max($excl) : null,
        ];
    }

    // =========================================================================
    // Maths helpers
    // =========================================================================

    private function mean(array $values): ?float
    {
        $n = count($values);
        return $n > 0 ? round(array_sum($values) / $n, 1) : null;
    }

    private function median(array $values): ?float
    {
        $n = count($values);
        if ($n === 0) return null;

        sort($values);
        $mid = intdiv($n, 2);
        return ($n % 2 === 0)
            ? ($values[$mid - 1] + $values[$mid]) / 2
            : (float) $values[$mid];
    }

    private function std(array $values): ?float
    {
        $n = count($values);
        if ($n < 2) return null;

        $mean = array_sum($values) / $n;
        $variance = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $values)) / ($n - 1);
        return round(sqrt($variance), 1);
    }
}
