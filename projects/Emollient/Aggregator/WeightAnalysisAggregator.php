<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AbstractAggregator;
use CEL\Shared\Domain\Aggregator\AggregatorInterface;

/**
 * WeightAnalysisAggregator
 *
 * Computes all weight-related metrics for enrolled babies across three
 * data sources fetched in a single REDCap stream:
 *
 *   day0_arm_1        ->  baby_eligible_enroll  (twin/triplet filter - "Yes" row only)
 *                         baby_birth_wt_hosp    (birth weight, grams)
 *                         baby_weight_nicu      (admission weight, grams)
 *                         baby_sex              (coded: M/F)
 *                         baby_hosp_code        (site - fallback if discharge form absent)
 *
 *   day1_arm_1 to     ->  dcm_newborn_weight_1  (grams)
 *   day28_arm_1           dcm_newborn_weight_2  (grams)
 *
 *   discharge_arm_1   ->  dis_baby_weight_1     (grams)
 *                         dis_baby_weight_2     (grams)
 *                         dis_hosp_code         (site - authoritative source)
 *                         dis_study_arm         (Intervention / Control)
 *                         dis_in_hosp           (Y = still in hospital at Day 28)
 *                         dis_datetime          (actual discharge datetime)
 *
 * Weight resolution: avg(w1, w2) when both present; falls back to whichever
 * is non-null and non-zero. All values stored in GRAMS throughout.
 *
 * Growth velocity formula (Fenton/ESPGHAN neonatal standard):
 *   velocity (g/kg/day) = (W2 - W1) / ((W1+W2)/2) / los_days * 1000
 * where W1 = admission weight in kg, W2 = discharge weight in kg.
 * Normalising by average weight makes babies of different sizes comparable.
 * Healthy preterm range: 15-20 g/kg/day.
 *
 * Output shape:
 * [
 *   'patients'       => [record_id => PATIENT_ROW, ...],
 *   'by_site'        => [site_code => SUMMARY_STATS, ..., 'Total' => SUMMARY_STATS],
 *   'by_arm'         => ['Intervention'=>..., 'Control'=>..., 'Total'=>...],
 *   'by_site_sex'    => [site_code => ['M'=>SUMMARY_STATS, 'F'=>...], 'Total'=>[...]],
 *   'trajectory'     => [day_index => [site_code => mean_g, 'All'=>mean_g], ...],
 *                        day 0  = admission weight
 *                        day 1-28 = daily weights
 *                        day 29 = discharge weight
 *   'regain_by_site' => [site_code => ['n'=>N,'by7'=>%,'by14'=>%,'by21'=>%,'by28'=>%,'never'=>%]],
 *   'period'         => [...],  // injected by engine
 * ]
 */
class WeightAnalysisAggregator extends AbstractAggregator
{
    private string $primaryKey;
    private array  $siteFilter;

    // Per-record collection buckets (all keyed by record_id)
    private array $birthWt       = [];
    private array $admissionWt   = [];
    private array $sex           = [];
    private array $site          = [];
    private array $studyArm      = [];
    private array $dailyWts      = [];   // record_id => [dayNum => float]
    private array $dischargeWt   = [];
    private array $inHospDay28   = [];
    private array $disDatetime   = [];
    private array $admissionDate = [];

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

            if ($event === 'day0_arm_1')
            {
                $this->collectScreeningRow($id, $row);
            }
            elseif ($event === 'discharge_arm_1')
            {
                $this->collectDischargeRow($id, $row);
            }
            elseif (preg_match('/^day(\d+)_arm_\d+$/', $event, $m))
            {
                $dayNum = (int)$m[1];
                if ($dayNum >= 1 && $dayNum <= 28) {
                    $this->collectDailyRow($id, $dayNum, $row);
                }
            }
        }

        return $this->resolve();
    }

    // =========================================================================
    // Stream collection helpers
    // =========================================================================

    private function collectScreeningRow(string $id, array $row): void
    {
        if (($row['baby_eligible_enroll'] ?? '') !== 'Yes') return;

        $bw  = $this->parseWeight($row['baby_birth_wt_hosp'] ?? null);
        $aw  = $this->parseWeight($row['baby_weight_nicu']   ?? null);
        $sex = strtoupper(trim($row['baby_sex'] ?? ''));

        if ($bw  !== null) $this->birthWt[$id]     = $bw;
        if ($aw  !== null) $this->admissionWt[$id] = $aw;
        if ($sex !== '')   $this->sex[$id]          = $sex;

        $raw = trim($row['baby_datetime_admission'] ?? '');
        if ($raw !== '') {
            try { $this->admissionDate[$id] = new \DateTime($raw); }
            catch (\Exception) {}
        }

        // Use screening site as fallback only when discharge form not yet present
        $hosp = trim($row['baby_hosp_code'] ?? '');
        if ($hosp !== '' && !isset($this->site[$id])) {
            $this->site[$id] = $hosp;
        }
    }

    private function collectDischargeRow(string $id, array $row): void
    {
        $site = trim($row['dis_hosp_code'] ?? '');
        if ($site === '') return;   // no site = unusable row

        $arm = trim($row['dis_study_arm'] ?? '');
        $this->site[$id] = $site;  // discharge form is authoritative
        if ($arm !== '') $this->studyArm[$id] = $arm;

        $dw = $this->avgWeights(
            $row['dis_baby_weight_1'] ?? null,
            $row['dis_baby_weight_2'] ?? null
        );
        if ($dw !== null) $this->dischargeWt[$id] = $dw;

        $inHosp = trim($row['dis_in_hosp'] ?? '');
        if ($inHosp === '') return;

        $this->inHospDay28[$id] = ($inHosp === 'Y');

        if (!$this->inHospDay28[$id]) {
            $raw = trim($row['dis_datetime'] ?? '');
            if ($raw !== '') {
                try { $this->disDatetime[$id] = new \DateTime($raw); }
                catch (\Exception) {}
            }
        }
    }

    private function collectDailyRow(string $id, int $dayNum, array $row): void
    {
        $w = $this->avgWeights(
            $row['dcm_newborn_weight_1'] ?? null,
            $row['dcm_newborn_weight_2'] ?? null
        );
        if ($w !== null) {
            $this->dailyWts[$id][$dayNum] = $w;
        }
    }

    // =========================================================================
    // Resolution
    // =========================================================================

    private function resolve(): array
    {
        $patients   = [];
        $bySiteRaw  = [];
        $byArmRaw   = [];
        $bySiteSexR = [];
        $trajectory = [];    // [day => [site => [weights...]]]
        $regainRaw  = [];    // [site => [[7=>bool,14=>bool,21=>bool,28=>bool],...]]

        $allIds = array_unique(array_merge(
            array_keys($this->admissionWt),
            array_keys($this->dischargeWt),
            array_keys($this->site)
        ));

        foreach ($allIds as $id)
        {
            $site    = $this->site[$id]          ?? null;
            $arm     = $this->studyArm[$id]      ?? '';
            $sex     = $this->sex[$id]            ?? '';
            $birthWt = $this->birthWt[$id]        ?? null;
            $admWt   = $this->admissionWt[$id]    ?? null;
            $disWt   = $this->dischargeWt[$id]    ?? null;
            $daily   = $this->dailyWts[$id]       ?? [];
            $inH     = $this->inHospDay28[$id]    ?? null;
            $admDate = $this->admissionDate[$id]  ?? null;
            $disDate = $this->disDatetime[$id]    ?? null;

            if ($site === null) continue;

            if (!empty($this->siteFilter) && !in_array($site, $this->siteFilter, true)) {
                continue;
            }

            $losDays   = $this->computeLos($admDate, $inH, $disDate);
            $velocity  = $this->computeVelocity($admWt, $disWt, $losDays);
            $totalGain = ($admWt !== null && $disWt !== null) ? round($disWt - $admWt, 1) : null;
            $pctChange = ($admWt !== null && $admWt > 0 && $totalGain !== null)
                ? round($totalGain / $admWt * 100, 1) : null;

            [$daysToRegain, $regainedBy] = $this->computeRegain($birthWt, $daily, $disWt, $losDays);

            $patients[$id] = [
                'site'              => $site,
                'study_arm'         => $arm,
                'sex'               => $sex,
                'birth_wt'          => $birthWt,
                'admission_wt'      => $admWt,
                'discharge_wt'      => $disWt,
                'daily_weights'     => $daily,
                'total_gain_g'      => $totalGain,
                'pct_change'        => $pctChange,
                'velocity'          => $velocity,
                'los_days'          => $losDays,
                'days_to_regain_bw' => $daysToRegain,
                'regained_by_day'   => $regainedBy,
            ];

            // Accumulate into summary buckets
            foreach ([[$site, &$bySiteRaw], ['Total', &$bySiteRaw]] as [$key, &$map]) {
                $this->accumulate($map[$key], $patients[$id]);
            }
            $armKey = $arm ?: 'Unknown';
            foreach ([[$armKey, &$byArmRaw], ['Total', &$byArmRaw]] as [$key, &$map]) {
                $this->accumulate($map[$key], $patients[$id]);
            }
            if ($sex !== '') {
                $this->accumulate($bySiteSexR[$site][$sex], $patients[$id]);
                $this->accumulate($bySiteSexR['Total'][$sex], $patients[$id]);
            }

            // Trajectory
            if ($admWt !== null) {
                $trajectory[0][$site][] = $admWt;
                $trajectory[0]['All'][] = $admWt;
            }
            foreach ($daily as $day => $w) {
                $trajectory[$day][$site][] = $w;
                $trajectory[$day]['All'][]  = $w;
            }
            if ($disWt !== null) {
                $trajectory[29][$site][] = $disWt;
                $trajectory[29]['All'][]  = $disWt;
            }

            // Regain tracker (only for babies with known birth weight)
            if ($birthWt !== null) {
                $regainRaw[$site][]  = $regainedBy;
                $regainRaw['Total'][]= $regainedBy;
            }
        }

        // Build final arrays
        $bySite     = $this->buildAllStats($bySiteRaw);
        $byArm      = $this->buildAllStats($byArmRaw);
        $bySiteSex  = [];
        foreach ($bySiteSexR as $grp => $sexMap) {
            foreach ($sexMap as $s => $raw) {
                $bySiteSex[$grp][$s] = $this->buildStats($raw);
            }
        }

        // Trajectory means
        $trajMeans = [];
        foreach ($trajectory as $day => $siteWts) {
            foreach ($siteWts as $grp => $wts) {
                $trajMeans[$day][$grp] = count($wts) > 0
                    ? round(array_sum($wts) / count($wts), 1) : null;
            }
        }
        ksort($trajMeans);

        // Regain percentages
        $regainPct = [];
        foreach ($regainRaw as $grp => $rows) {
            $n = count($rows);
            if ($n === 0) continue;
            $cnt = fn($t) => count(array_filter($rows, fn($r) => $r[$t] ?? false));
            $regainPct[$grp] = [
                'n'    => $n,
                'by7'  => round($cnt(7)  / $n * 100, 1),
                'by14' => round($cnt(14) / $n * 100, 1),
                'by21' => round($cnt(21) / $n * 100, 1),
                'by28' => round($cnt(28) / $n * 100, 1),
                'never'=> round((1 - $cnt(28) / $n) * 100, 1),
            ];
        }

        // Total last in every map
        foreach ([&$bySite, &$byArm, &$bySiteSex, &$trajMeans, &$regainPct] as &$arr) {
            if (isset($arr['Total'])) {
                $t = $arr['Total'];
                unset($arr['Total']);
                $arr['Total'] = $t;
            }
        }
        unset($arr);

        return [
            'patients'       => $patients,
            'by_site'        => $bySite,
            'by_arm'         => $byArm,
            'by_site_sex'    => $bySiteSex,
            'trajectory'     => $trajMeans,
            'regain_by_site' => $regainPct,
        ];
    }

    // =========================================================================
    // Business rules
    // =========================================================================

    /**
     * Growth velocity (g/kg/day) using Fenton/ESPGHAN formula.
     * W1 and W2 in grams, converted to kg internally.
     */
    private function computeVelocity(?float $admWtG, ?float $disWtG, ?int $los): ?float
    {
        if ($admWtG === null || $disWtG === null || $los === null || $los <= 0) {
            return null;
        }
        $w1  = $admWtG / 1000;
        $w2  = $disWtG / 1000;
        $avg = ($w1 + $w2) / 2;
        if ($avg <= 0) return null;

        return round(($w2 - $w1) / $avg / $los * 1000, 1);
    }

    /** Mirrors LengthOfStayAggregator::computeLos() exactly. */
    private function computeLos(?\DateTime $adm, ?bool $inH, ?\DateTime $dis): ?int
    {
        if ($inH === null)  return null;
        if ($inH === true)  return 28;
        if ($adm === null || $dis === null) return null;
        if ($dis < $adm)    return null;
        return (int) ceil(($dis->getTimestamp() - $adm->getTimestamp()) / 86400);
    }

    /**
     * Returns [days_to_regain|null, [7=>bool, 14=>bool, 21=>bool, 28=>bool]].
     * Scans daily weights days 1-28, then discharge weight as final check.
     */
    private function computeRegain(?float $birthWt, array $daily, ?float $disWt, ?int $los): array
    {
        $by = [7 => false, 14 => false, 21 => false, 28 => false];
        if ($birthWt === null || $birthWt <= 0) return [null, $by];

        $dtr = null;
        for ($d = 1; $d <= 28; $d++) {
            $w = $daily[$d] ?? null;
            if ($w !== null && $dtr === null && $w >= $birthWt) {
                $dtr = $d;
            }
        }
        if ($dtr === null && $disWt !== null && $disWt >= $birthWt && $los !== null) {
            $dtr = $los;
        }

        foreach ([7, 14, 21, 28] as $t) {
            $by[$t] = ($dtr !== null && $dtr <= $t);
        }
        return [$dtr, $by];
    }

    // =========================================================================
    // Accumulation
    // =========================================================================

    private function accumulate(mixed &$b, array $p): void
    {
        $b ??= [
            'bw'=>[], 'aw'=>[], 'dw'=>[], 'gains'=>[], 'pcts'=>[], 'vels'=>[],
            'pos'=>0, 'n'=>0,
            'reg'=>[7=>0, 14=>0, 21=>0, 28=>0, 'nbw'=>0],
        ];
        $b['n']++;
        if ($p['birth_wt']    !== null) $b['bw'][]    = $p['birth_wt'];
        if ($p['admission_wt']!== null) $b['aw'][]    = $p['admission_wt'];
        if ($p['discharge_wt']!== null) $b['dw'][]    = $p['discharge_wt'];
        if ($p['total_gain_g']!== null) {
            $b['gains'][] = $p['total_gain_g'];
            if ($p['total_gain_g'] > 0) $b['pos']++;
        }
        if ($p['pct_change'] !== null)  $b['pcts'][]  = $p['pct_change'];
        if ($p['velocity']   !== null)  $b['vels'][]  = $p['velocity'];
        if ($p['birth_wt']   !== null) {
            $b['reg']['nbw']++;
            foreach ([7,14,21,28] as $t) {
                if ($p['regained_by_day'][$t] ?? false) $b['reg'][$t]++;
            }
        }
    }

    private function buildAllStats(array $rawMap): array
    {
        $out = [];
        foreach ($rawMap as $k => $raw) {
            $out[$k] = $this->buildStats($raw);
        }
        return $out;
    }

    private function buildStats(array $b): array
    {
        $n   = $b['n'];
        $nbw = $b['reg']['nbw'];
        $v   = $b['vels'];
        sort($v);
        $pct = fn($cnt) => $nbw > 0 ? round($cnt / $nbw * 100, 1) : null;

        return [
            'n'                  => $n,
            'mean_birth_wt'      => $this->mean($b['bw']),
            'mean_admission_wt'  => $this->mean($b['aw']),
            'mean_discharge_wt'  => $this->mean($b['dw']),
            'mean_gain_g'        => $this->mean($b['gains']),
            'mean_pct_change'    => $this->mean($b['pcts']),
            'n_positive_gain'    => $b['pos'],
            'pct_positive_gain'  => $n > 0 ? round($b['pos'] / $n * 100, 1) : null,
            'mean_velocity'      => $this->mean($v),
            'median_velocity'    => $this->median($v),
            'std_velocity'       => $this->std($v),
            'min_velocity'       => count($v) ? $v[0]            : null,
            'max_velocity'       => count($v) ? $v[count($v)-1]  : null,
            'n_regain_bw'        => $nbw,
            'n_regained_by_7'    => $b['reg'][7],
            'n_regained_by_14'   => $b['reg'][14],
            'n_regained_by_21'   => $b['reg'][21],
            'n_regained_by_28'   => $b['reg'][28],
            'pct_regained_by_7'  => $pct($b['reg'][7]),
            'pct_regained_by_14' => $pct($b['reg'][14]),
            'pct_regained_by_21' => $pct($b['reg'][21]),
            'pct_regained_by_28' => $pct($b['reg'][28]),
        ];
    }

    // =========================================================================
    // Weight helpers
    // =========================================================================

    private function parseWeight(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') return null;
        $v = (float)$raw;
        if ($v <= 0 || $v > 10000) return null;
        return $v;
    }

    private function avgWeights(mixed $w1, mixed $w2): ?float
    {
        $v1 = $this->parseWeight($w1);
        $v2 = $this->parseWeight($w2);
        if ($v1 !== null && $v2 !== null) return round(($v1 + $v2) / 2, 1);
        return $v1 ?? $v2;
    }

    // =========================================================================
    // Stats helpers
    // =========================================================================

    private function mean(array $vals): ?float
    {
        $n = count($vals);
        return $n > 0 ? round(array_sum($vals) / $n, 1) : null;
    }

    private function median(array $sorted): ?float
    {
        $n = count($sorted);
        if ($n === 0) return null;
        $mid = intdiv($n, 2);
        return ($n % 2 === 0)
            ? round(($sorted[$mid-1] + $sorted[$mid]) / 2, 1)
            : (float)$sorted[$mid];
    }

    private function std(array $vals): ?float
    {
        $n = count($vals);
        if ($n < 2) return null;
        $m = array_sum($vals) / $n;
        return round(sqrt(array_sum(array_map(fn($v) => ($v-$m)**2, $vals)) / ($n-1)), 1);
    }
}
