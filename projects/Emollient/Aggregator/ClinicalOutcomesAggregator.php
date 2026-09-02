<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AbstractAggregator;

/**
 * ClinicalOutcomesAggregator
 *
 * Computes all primary and secondary clinical outcomes for the Emollient trial.
 *
 * ── Denominators ─────────────────────────────────────────────────────────────
 *
 *   N1 = Total enrolled
 *        All record_ids where enr_study_arm != '' on day0_arm_1
 *
 *   N2 = Protocol-complete cohort
 *        N1 minus babies whose discharge type is one of:
 *        TYP_REF | TYP_ABS | TYP_LAMA | TYP_DOPR
 *        checked in dis_discharge_type OR dis_post_28_discharge_type
 *
 *   Both denominators are shown for every outcome.
 *   Arm classification uses enr_study_arm from day0_arm_1.
 *
 * ── Primary Outcome ──────────────────────────────────────────────────────────
 *
 *   P1 — Pre-discharge mortality
 *        Numerator: dis_discharge_type = 'TYP_DEA'
 *        Denominator: N1 and N2
 *        Arm: dis_study_arm (discharge form) cross-checked with enr_study_arm
 *
 * ── Secondary Outcomes ───────────────────────────────────────────────────────
 *
 *   S1 — Neonatal mortality at Day 29
 *        Numerator: fu28_alive_day28 = 'ALV_N' on day29_arm_1
 *        Arm: fu28_study_arm on day29_arm_1
 *
 *   S2 — Incidence of suspected sepsis (day0–day28)
 *        Episode criteria (must meet BOTH):
 *          Clinical sign (ANY ONE):
 *            nss_high_temp = 'Yes'  OR
 *            nss_low_temp  = 'Yes'  OR
 *            nss_rr        = 'Yes'  OR
 *            nss_neuro_sign___sepsis_neuro_seizure = '1'
 *          Lab criteria (ANY TWO of four):
 *            nss_tlc       < 5000
 *            nss_anc       < 1800
 *            nss_micro_esr > 15
 *            nss_crp       > 10
 *        Incidence = baby has at least one qualifying episode across day0–day28
 *
 *   S3 — Skin condition scores (raw data for LGM in R/SPSS)
 *        Events: enrolment, day3, day7, day14, day28 (day0/3/7/14/28_arm_1)
 *        Fields: scs_hosp_code, scs_condition
 *        Output: per-baby per-timepoint scores for export
 *
 *   S4 — Duration of hospital stay
 *        dis_age_discharge where dis_in_hosp = 'N'
 *        OR dis_post_28_age_discharge
 *
 *   S5 — Weight gain velocity (g/kg/day)
 *        Discharge weight = avg(dis_baby_weight_1, dis_baby_weight_2)
 *        Birth weight     = baby_birth_wt_hosp (day0_arm_1)
 *        n                = completed age at discharge (dis_age_discharge)
 *        Formula: (Wdis − Wbirth) / ((Wbirth + Wdis)/2) / n × 1000
 *
 *   S6 — Head circumference growth velocity (mm/week)
 *        HC discharge = avg(dis_head_circumference_1, dis_head_circumference_2)
 *        HC birth     = baby_head_circumference (day0_arm_1)
 *        Formula: (HCdis − HCbirth) / n × 7
 *
 *   S7 — MUAC growth velocity (mm/week)
 *        MUAC discharge = avg(dis_muac_1, dis_muac_2)
 *        MUAC birth     = baby_muac (day0_arm_1)
 *        Formula: (MUACdis − MUACbirth) / n × 7
 *
 * ── Output Shape ─────────────────────────────────────────────────────────────
 *
 * [
 *   'denominators' => [
 *     'n1'         => ['total'=>int, 'by_site'=>[site=>int], 'by_arm'=>[arm=>int]],
 *     'n2'         => ['total'=>int, 'by_site'=>[site=>int], 'by_arm'=>[arm=>int]],
 *     'excluded'   => [record_id => dis_type],   // for audit
 *   ],
 *   'primary' => [
 *     'mortality'  => OUTCOME_RESULT,
 *   ],
 *   'secondary' => [
 *     'neonatal_mortality' => OUTCOME_RESULT,
 *     'sepsis'             => SEPSIS_RESULT,
 *     'skin_scores'        => SKIN_RESULT,
 *     'los'                => VELOCITY_RESULT,
 *     'weight_velocity'    => VELOCITY_RESULT,
 *     'hc_velocity'        => VELOCITY_RESULT,
 *     'muac_velocity'      => VELOCITY_RESULT,
 *   ],
 *   'site_labels' => [...],
 *   'period'      => [...],
 * ]
 *
 * Where OUTCOME_RESULT is:
 * [
 *   'by_arm' => [
 *     'Intervention' => ['n1'=>int, 'n2'=>int, 'events_n1'=>int, 'events_n2'=>int,
 *                        'rate_n1'=>float|null, 'rate_n2'=>float|null],
 *     'Control'      => [...],
 *     'Total'        => [...],
 *   ],
 *   'by_site' => [site => same shape],
 *   'patients'=> [record_id => ['site'=>str,'arm'=>str,'event'=>bool,'excluded'=>bool]],
 * ]
 *
 * Where VELOCITY_RESULT is:
 * [
 *   'by_arm'  => ['Intervention'=>STATS, 'Control'=>STATS, 'Total'=>STATS],
 *   'by_site' => [site => STATS],
 *   'patients'=> [record_id => ['site'=>str,'arm'=>str,'value'=>float|null,'n'=>int|null]],
 * ]
 * STATS = ['count'=>int,'mean'=>float|null,'median'=>float|null,'sd'=>float|null,
 *           'min'=>float|null,'max'=>float|null,'missing'=>int]
 */
class ClinicalOutcomesAggregator extends AbstractAggregator
{
    private const EXCLUDE_TYPES = ['TYP_REF', 'TYP_ABS', 'TYP_LAMA', 'TYP_DOPR'];

    private const SEPSIS_EVENTS = [
        'day0_arm_1','day1_arm_1','day2_arm_1','day3_arm_1','day4_arm_1',
        'day5_arm_1','day6_arm_1','day7_arm_1','day8_arm_1','day9_arm_1',
        'day10_arm_1','day11_arm_1','day12_arm_1','day13_arm_1','day14_arm_1',
        'day15_arm_1','day16_arm_1','day17_arm_1','day18_arm_1','day19_arm_1',
        'day20_arm_1','day21_arm_1','day22_arm_1','day23_arm_1','day24_arm_1',
        'day25_arm_1','day26_arm_1','day27_arm_1','day28_arm_1',
    ];

    private const SKIN_EVENTS = [
        'day0_arm_1'  => 0,
        'day3_arm_1'  => 3,
        'day7_arm_1'  => 7,
        'day14_arm_1' => 14,
        'day28_arm_1' => 28,
    ];

    // Baseline event for anthropometric measurements
    private const BASELINE_EVENT = 'baseline_arm_1';

    private string $primaryKey;
    private array  $siteFilter;

    // ── Per-record buckets ────────────────────────────────────────────────────

    private array $enrolled     = [];  // record_id => true
    private array $arm          = [];  // record_id => 'Intervention'|'Control'
    private array $site         = [];  // record_id => site_code (from day0_arm_1)

    // Discharge
    private array $disType      = [];  // record_id => dis_discharge_type
    private array $disPost28    = [];  // record_id => dis_post_28_discharge_type
    private array $disMortality = [];  // record_id => bool (TYP_DEA)
    private array $disArm       = [];  // record_id => dis_study_arm
    private array $disSite      = [];  // record_id => dis_hosp_code
    private array $disAge       = [];  // record_id => int|null (dis_age_discharge days)
    private array $disInHosp    = [];  // record_id => bool
    private array $enrAge       = [];  // record_id => int (baby age in days at enrollment)
    private array $disPost28Age = [];  // record_id => int|null

    // Day 0 anthropometrics
    private array $birthWt      = [];  // record_id => float (grams)
    private array $birthHC      = [];  // record_id => float (mm)
    private array $birthMuac    = [];  // record_id => float (mm)

    // Discharge anthropometrics
    private array $disWt        = [];  // record_id => float (grams)
    private array $disHC        = [];  // record_id => float (mm)
    private array $disMuac      = [];  // record_id => float (mm)

    // Day 29 follow-up
    private array $fu28Dead     = [];  // record_id => bool (ALV_N)
    private array $fu28Arm      = [];  // record_id => string

    // Sepsis: record_id => bool (has at least one episode)
    private array $sepsisCase   = [];

    // Skin scores: record_id => [day => score]
    private array $skinScores   = [];

    // =========================================================================

    public function __construct(string $primaryKey, array $siteFilter = [])
    {
        $this->primaryKey = $primaryKey;
        $this->siteFilter = array_map('trim', $siteFilter);
    }

    // =========================================================================
    // AggregatorInterface
    // =========================================================================

    public function aggregate(iterable $records): array
    {
        foreach ($records as $row) {
            $id = trim((string)($row[$this->primaryKey] ?? ''));
            if ($id === '') continue;

            $event = trim($row['redcap_event_name'] ?? '');

            if ($event === 'day0_arm_1') {
                $this->collectDay0($id, $row);
            }

            if ($event === self::BASELINE_EVENT) {
                $this->collectBaseline($id, $row);
            }

            if ($event === 'discharge_arm_1') {
                $this->collectDischarge($id, $row);
            }

            if ($event === 'day29_arm_1') {
                $this->collectDay29($id, $row);
            }

            if (in_array($event, self::SEPSIS_EVENTS, true)) {
                $this->collectSepsisEvent($id, $row);
            }

            if (isset(self::SKIN_EVENTS[$event])) {
                $this->collectSkinScore($id, $row, self::SKIN_EVENTS[$event]);
            }
        }

        return $this->resolve();
    }

    // =========================================================================
    // Stream collectors
    // =========================================================================

    private function collectDay0(string $id, array $row): void
    {
        $arm  = trim($row['enr_study_arm'] ?? '');
        $site = trim($row['enr_hosp_code'] ?? $row['baby_hosp_code'] ?? '');

        if ($arm === '') return;   // not enrolled

        if (!empty($this->siteFilter) && !in_array($site, $this->siteFilter, true)) return;

        $this->enrolled[$id] = true;
        $this->arm[$id]      = $arm;
        $this->site[$id]     = $site;

        // Baby age at enrollment (for LOS calculation)
        $enrAge = $this->num($row['enr_baby_age_days'] ?? $row['baby_age_days'] ?? '');
        if ($enrAge !== null) $this->enrAge[$id] = (int)$enrAge;

        // Birth weight from day0
        $bw = $this->num($row['baby_birth_wt_hosp'] ?? '');
        if ($bw !== null) $this->birthWt[$id] = $bw;

        // HC and MUAC at day0 — may be overridden by baseline_arm_1 values
        $hc = $this->num($row['baby_head_circumference'] ?? '');
        if ($hc !== null) $this->birthHC[$id] = $hc;

        $muac = $this->num($row['baby_muac'] ?? '');
        if ($muac !== null) $this->birthMuac[$id] = $muac;
    }

    private function collectBaseline(string $id, array $row): void
    {
        if (!isset($this->enrolled[$id])) return;

        // HC: average of base_anthro_head_circumference_1 and _2
        $hc = $this->avg(
            $this->num($row['base_anthro_head_circumference_1'] ?? ''),
            $this->num($row['base_anthro_head_circumference_2'] ?? '')
        );
        if ($hc !== null) $this->birthHC[$id] = $hc;

        // MUAC from baseline
        $muac = $this->num($row['base_anthro_muac_circumference'] ?? '');
        if ($muac !== null) $this->birthMuac[$id] = $muac;
    }

    private function collectDischarge(string $id, array $row): void
    {
        $site = trim($row['dis_hosp_code'] ?? '');
        if ($site !== '') $this->disSite[$id] = $site;

        $arm = trim($row['dis_study_arm'] ?? '');
        if ($arm !== '') $this->disArm[$id] = $arm;

        $dtype = trim($row['dis_discharge_type']        ?? '');
        $ptype = trim($row['dis_post_28_discharge_type'] ?? '');

        if ($dtype !== '') $this->disType[$id]   = $dtype;
        if ($ptype !== '') $this->disPost28[$id]  = $ptype;

        $this->disMortality[$id] = ($dtype === 'TYP_DEA');

        $inHosp = trim($row['dis_in_hosp'] ?? '');
        $this->disInHosp[$id] = ($inHosp === 'Y');

        // Age at discharge (days)
        $age = $this->num($row['dis_age_discharge'] ?? '');
        if ($age !== null) $this->disAge[$id] = (int)$age;

        $age28 = $this->num($row['dis_post_28_age_discharge'] ?? '');
        if ($age28 !== null) $this->disPost28Age[$id] = (int)$age28;

        // Discharge anthropometrics
        $wt = $this->avg(
            $this->num($row['dis_baby_weight_1'] ?? ''),
            $this->num($row['dis_baby_weight_2'] ?? '')
        );
        if ($wt !== null) $this->disWt[$id] = $wt;

        $hc = $this->avg(
            $this->num($row['dis_head_circumference_1'] ?? ''),
            $this->num($row['dis_head_circumference_2'] ?? '')
        );
        if ($hc !== null) $this->disHC[$id] = $hc;

        $muac = $this->avg(
            $this->num($row['dis_muac_1'] ?? ''),
            $this->num($row['dis_muac_2'] ?? '')
        );
        if ($muac !== null) $this->disMuac[$id] = $muac;
    }

    private function collectDay29(string $id, array $row): void
    {
        $alive = trim($row['fu28_alive_day28'] ?? '');
        $arm   = trim($row['fu28_study_arm']   ?? '');

        if ($alive !== '') $this->fu28Dead[$id] = ($alive === 'ALV_N');
        if ($arm   !== '') $this->fu28Arm[$id]  = $arm;
    }

    private function collectSepsisEvent(string $id, array $row): void
    {
        // Skip if baby already flagged as sepsis case
        if (!empty($this->sepsisCase[$id])) return;
        // Skip if not enrolled
        if (!isset($this->enrolled[$id])) return;

        // ── Clinical sign — ANY ONE required ─────────────────────────────────
        // REDCap may return 'Yes', 'yes', '1' depending on field type
        $yn = fn($v) => in_array(strtolower(trim((string)$v)), ['yes','1','true'], true);

        $clinicalSign =
            $yn($row['nss_high_temp'] ?? '') ||
            $yn($row['nss_low_temp']  ?? '') ||
            $yn($row['nss_rr']        ?? '') ||
            ($row['nss_neuro_sign___sepsis_neuro_seizure'] ?? '') === '1';

        if (!$clinicalSign) return;

        // ── Lab criteria — ANY TWO of four ───────────────────────────────────
        $labCount = 0;

        $tlc = $this->num($row['nss_tlc']       ?? '');
        $anc = $this->num($row['nss_anc']        ?? '');
        $esr = $this->num($row['nss_micro_esr']  ?? '');
        $crp = $this->num($row['nss_crp']        ?? '');

        if ($tlc !== null && $tlc < 5000)  $labCount++;
        if ($anc !== null && $anc < 1800)  $labCount++;
        if ($esr !== null && $esr > 15)    $labCount++;
        if ($crp !== null && $crp > 10)    $labCount++;

        if ($labCount >= 2) {
            $this->sepsisCase[$id] = true;
        }
    }

    private function collectSkinScore(string $id, array $row, int $dayNum): void
    {
        if (!isset($this->enrolled[$id])) return;

        $score = $this->num($row['scs_condition'] ?? '');
        if ($score === null) return;

        if (!isset($this->skinScores[$id])) $this->skinScores[$id] = [];
        $this->skinScores[$id][$dayNum] = $score;
    }

    // =========================================================================
    // Resolve — build all output structures
    // =========================================================================

    private function resolve(): array
    {
        // ── Build enrolled cohort ─────────────────────────────────────────────
        $allIds = array_keys($this->enrolled);

        // ── Determine excluded records (N1 → N2) ─────────────────────────────
        $excluded = [];
        foreach ($allIds as $id) {
            $dtype = $this->disType[$id]  ?? '';
            $ptype = $this->disPost28[$id] ?? '';
            if (in_array($dtype, self::EXCLUDE_TYPES, true) ||
                in_array($ptype, self::EXCLUDE_TYPES, true)) {
                $excluded[$id] = $dtype ?: $ptype;
            }
        }

        $n2Ids = array_diff($allIds, array_keys($excluded));

        // ── Denominators ──────────────────────────────────────────────────────
        $denominators = [
            'n1'      => $this->buildDenominator($allIds),
            'n2'      => $this->buildDenominator($n2Ids),
            'excluded'=> $excluded,
        ];

        // ── Primary outcome ───────────────────────────────────────────────────
        $mortalityPatients = [];
        foreach ($allIds as $id) {
            $mortalityPatients[$id] = [
                'site'     => $this->site[$id] ?? ($this->disSite[$id] ?? ''),
                'arm'      => $this->arm[$id]  ?? ($this->disArm[$id] ?? ''),
                'event'    => $this->disMortality[$id] ?? false,
                'excluded' => isset($excluded[$id]),
            ];
        }

        $primary = [
            'mortality' => $this->buildOutcomeResult($mortalityPatients, $allIds, $n2Ids),
        ];

        // ── Secondary outcomes ────────────────────────────────────────────────

        // S1 — Neonatal mortality
        // = pre-discharge deaths (TYP_DEA) + deaths at day-29 follow-up (ALV_N)
        $nmPatients = [];
        foreach ($allIds as $id) {
            $predis  = $this->disMortality[$id] ?? false;
            $day29   = $this->fu28Dead[$id]     ?? false;
            $nmdead  = $predis || $day29;
            $nmPatients[$id] = [
                'site'         => $this->site[$id] ?? '',
                'arm'          => $this->fu28Arm[$id] ?? ($this->arm[$id] ?? ''),
                'event'        => $nmdead,
                'predis_death' => $predis,
                'day29_death'  => $day29,
                'excluded'     => isset($excluded[$id]),
                'missing'      => !isset($this->fu28Dead[$id]) && !isset($this->disMortality[$id]),
            ];
        }

        // S2 — Sepsis incidence
        $sepsisPatients = [];
        foreach ($allIds as $id) {
            $sepsisPatients[$id] = [
                'site'     => $this->site[$id] ?? '',
                'arm'      => $this->arm[$id]  ?? '',
                'event'    => !empty($this->sepsisCase[$id]),
                'excluded' => isset($excluded[$id]),
            ];
        }

        // S3 — Skin scores (raw — for R/SPSS export)
        $skinData = [];
        foreach ($allIds as $id) {
            $skinData[$id] = [
                'site'     => $this->site[$id] ?? '',
                'arm'      => $this->arm[$id]  ?? '',
                'excluded' => isset($excluded[$id]),
                'scores'   => $this->skinScores[$id] ?? [],
            ];
        }

        // S4 — Length of stay
        $losPatients = [];
        foreach ($allIds as $id) {
            $n  = $this->disAge[$id]     ?? $this->disPost28Age[$id] ?? null;
            // Day-28 in hospital = 28 days LOS
            if ($n === null && ($this->disInHosp[$id] ?? false)) $n = 28;
            $losPatients[$id] = [
                'site'     => $this->site[$id] ?? '',
                'arm'      => $this->arm[$id]  ?? '',
                'value'    => $n !== null ? (float)$n : null,
                'n'        => $n,
                'excluded' => isset($excluded[$id]),
            ];
        }

        // S5 — Weight velocity
        $wtPatients = [];
        foreach ($allIds as $id) {
            $w0 = $this->birthWt[$id] ?? null;
            $wn = $this->disWt[$id]   ?? null;
            $n  = $this->disAge[$id]  ?? $this->disPost28Age[$id] ?? null;
            if ($this->disInHosp[$id] ?? false) $n = 28;

            $vel = $this->weightVelocity($w0, $wn, $n);
            $wtPatients[$id] = [
                'site'       => $this->site[$id] ?? '',
                'arm'        => $this->arm[$id]  ?? '',
                'value'      => $vel,
                'n'          => $n,
                'birth_wt'   => $w0,
                'dis_wt'     => $wn,
                'excluded'   => isset($excluded[$id]),
            ];
        }

        // S6 — Head circumference velocity
        $hcPatients = [];
        foreach ($allIds as $id) {
            $v0 = $this->birthHC[$id] ?? null;
            $vn = $this->disHC[$id]   ?? null;
            $n  = $this->disAge[$id]  ?? $this->disPost28Age[$id] ?? null;
            if ($this->disInHosp[$id] ?? false) $n = 28;

            $vel = $this->linearVelocityPerWeek($v0, $vn, $n);
            $hcPatients[$id] = [
                'site'     => $this->site[$id] ?? '',
                'arm'      => $this->arm[$id]  ?? '',
                'value'    => $vel,
                'n'        => $n,
                'excluded' => isset($excluded[$id]),
            ];
        }

        // S7 — MUAC velocity
        $muacPatients = [];
        foreach ($allIds as $id) {
            $v0 = $this->birthMuac[$id] ?? null;
            $vn = $this->disMuac[$id]   ?? null;
            $n  = $this->disAge[$id]    ?? $this->disPost28Age[$id] ?? null;
            if ($this->disInHosp[$id] ?? false) $n = 28;

            $vel = $this->linearVelocityPerWeek($v0, $vn, $n);
            $muacPatients[$id] = [
                'site'     => $this->site[$id] ?? '',
                'arm'      => $this->arm[$id]  ?? '',
                'value'    => $vel,
                'n'        => $n,
                'excluded' => isset($excluded[$id]),
            ];
        }

        return [
            'denominators' => $denominators,
            'primary'      => [
                'mortality' => $this->buildOutcomeResult($mortalityPatients, $allIds, $n2Ids),
            ],
            'secondary'    => [
                'neonatal_mortality' => $this->buildOutcomeResult($nmPatients,     $allIds, $n2Ids),
                'sepsis'             => $this->buildOutcomeResult($sepsisPatients, $allIds, $n2Ids),
                'skin_scores'        => $this->buildSkinResult($skinData),
                'los'                => $this->buildVelocityResult($losPatients),
                'weight_velocity'    => $this->buildVelocityResult($wtPatients),
                'hc_velocity'        => $this->buildVelocityResult($hcPatients),
                'muac_velocity'      => $this->buildVelocityResult($muacPatients),
            ],
            'site_labels'  => [],
            'period'       => [],
        ];
    }

    // =========================================================================
    // Result builders
    // =========================================================================

    /**
     * Build OUTCOME_RESULT — binary event (mortality, sepsis etc.)
     */
    private function buildOutcomeResult(
        array $patients,
        array $n1Ids,
        array $n2Ids
    ): array {
        $n1Set = array_flip($n1Ids);
        $n2Set = array_flip($n2Ids);

        $byArm  = [];
        $bySite = [];

        foreach ($patients as $id => $p) {
            $arm  = $p['arm']  ?: 'Unknown';
            $site = $p['site'] ?: 'Unknown';
            $ev   = (bool)$p['event'];

            foreach ([$arm, 'Total'] as $a) {
                if (!isset($byArm[$a])) $byArm[$a] = $this->emptyOutcome();
                $byArm[$a]['n1']++;
                if (isset($n2Set[$id])) $byArm[$a]['n2']++;
                if ($ev) {
                    $byArm[$a]['events_n1']++;
                    if (isset($n2Set[$id])) $byArm[$a]['events_n2']++;
                }
            }

            foreach ([$site, 'Total'] as $s) {
                if (!isset($bySite[$s])) $bySite[$s] = $this->emptyOutcome();
                $bySite[$s]['n1']++;
                if (isset($n2Set[$id])) $bySite[$s]['n2']++;
                if ($ev) {
                    $bySite[$s]['events_n1']++;
                    if (isset($n2Set[$id])) $bySite[$s]['events_n2']++;
                }
            }
        }

        // Compute rates
        foreach ([&$byArm, &$bySite] as &$group) {
            foreach ($group as &$cell) {
                $cell['rate_n1'] = $cell['n1'] > 0
                    ? round($cell['events_n1'] / $cell['n1'] * 100, 2) : null;
                $cell['rate_n2'] = $cell['n2'] > 0
                    ? round($cell['events_n2'] / $cell['n2'] * 100, 2) : null;
            }
        }

        return [
            'by_arm'   => $byArm,
            'by_site'  => $bySite,
            'patients' => $patients,
        ];
    }

    private function emptyOutcome(): array
    {
        return [
            'n1' => 0, 'n2' => 0,
            'events_n1' => 0, 'events_n2' => 0,
            'rate_n1' => null, 'rate_n2' => null,
        ];
    }

    /**
     * Build VELOCITY_RESULT — continuous measurement (LOS, weight velocity etc.)
     */
    private function buildVelocityResult(array $patients): array
    {
        $byArm  = [];
        $bySite = [];

        foreach ($patients as $id => $p) {
            $arm   = $p['arm']   ?: 'Unknown';
            $site  = $p['site']  ?: 'Unknown';
            $value = $p['value'];

            foreach ([$arm, 'Total'] as $a) {
                if (!isset($byArm[$a])) $byArm[$a] = ['values' => [], 'missing' => 0];
                if ($value !== null) $byArm[$a]['values'][] = $value;
                else                 $byArm[$a]['missing']++;
            }

            foreach ([$site, 'Total'] as $s) {
                if (!isset($bySite[$s])) $bySite[$s] = ['values' => [], 'missing' => 0];
                if ($value !== null) $bySite[$s]['values'][] = $value;
                else                 $bySite[$s]['missing']++;
            }
        }

        // Compute stats
        foreach ([&$byArm, &$bySite] as &$group) {
            foreach ($group as &$cell) {
                $cell = array_merge(
                    $this->computeStats($cell['values']),
                    ['missing' => $cell['missing']]
                );
            }
        }

        return [
            'by_arm'   => $byArm,
            'by_site'  => $bySite,
            'patients' => $patients,
        ];
    }

    /**
     * Build skin score result — raw scores per timepoint per arm for LGM export
     */
    private function buildSkinResult(array $patients): array
    {
        $byArmByDay = [];  // arm => day => [scores]

        foreach ($patients as $id => $p) {
            $arm = $p['arm'] ?: 'Unknown';
            foreach ($p['scores'] as $day => $score) {
                if (!isset($byArmByDay[$arm][$day])) $byArmByDay[$arm][$day] = [];
                $byArmByDay[$arm][$day][] = $score;
                if (!isset($byArmByDay['Total'][$day])) $byArmByDay['Total'][$day] = [];
                $byArmByDay['Total'][$day][] = $score;
            }
        }

        // Mean score per arm per day
        $trajectory = [];
        foreach ($byArmByDay as $arm => $days) {
            foreach ($days as $day => $scores) {
                $trajectory[$arm][$day] = [
                    'n'    => count($scores),
                    'mean' => round(array_sum($scores) / count($scores), 2),
                    'sd'   => $this->sd($scores),
                ];
            }
        }

        return [
            'trajectory' => $trajectory,
            'patients'   => $patients,   // full raw data for R/SPSS CSV export
        ];
    }

    /**
     * Build denominator structure
     */
    private function buildDenominator(array $ids): array
    {
        $bySite = [];
        $byArm  = [];

        foreach ($ids as $id) {
            $site = $this->site[$id] ?? 'Unknown';
            $arm  = $this->arm[$id]  ?? 'Unknown';

            $bySite[$site]    = ($bySite[$site]    ?? 0) + 1;
            $bySite['Total']  = ($bySite['Total']  ?? 0) + 1;
            $byArm[$arm]      = ($byArm[$arm]      ?? 0) + 1;
            $byArm['Total']   = ($byArm['Total']   ?? 0) + 1;
        }

        return [
            'total'   => count($ids),
            'by_site' => $bySite,
            'by_arm'  => $byArm,
        ];
    }

    // =========================================================================
    // Formulae
    // =========================================================================

    /**
     * Weight gain velocity in g/kg/day (Fenton/ESPGHAN neonatal standard).
     * Normalises by average weight to make babies of different sizes comparable.
     */
    private function weightVelocity(?float $w0, ?float $wn, ?int $n): ?float
    {
        if ($w0 === null || $wn === null || $n === null || $n <= 0) return null;
        if ($w0 <= 0 || $wn <= 0) return null;

        $avgWt = ($w0 + $wn) / 2;
        return round(($wn - $w0) / $avgWt / $n * 1000, 2);
    }

    /**
     * Linear measurement velocity in mm/week (HC and MUAC).
     */
    private function linearVelocityPerWeek(?float $v0, ?float $vn, ?int $n): ?float
    {
        if ($v0 === null || $vn === null || $n === null || $n <= 0) return null;
        return round(($vn - $v0) / $n * 7, 2);
    }

    // =========================================================================
    // Statistics helpers
    // =========================================================================

    private function computeStats(array $values): array
    {
        $n = count($values);
        if ($n === 0) {
            return ['count'=>0,'mean'=>null,'median'=>null,'sd'=>null,'min'=>null,'max'=>null];
        }
        sort($values);
        $mean = array_sum($values) / $n;
        return [
            'count'  => $n,
            'mean'   => round($mean, 2),
            'median' => round($this->median($values), 2),
            'sd'     => $this->sd($values),
            'min'    => round(min($values), 2),
            'max'    => round(max($values), 2),
        ];
    }

    private function median(array $sorted): float
    {
        $n = count($sorted);
        if ($n === 0) return 0;
        $mid = (int)($n / 2);
        return $n % 2 === 0
            ? ($sorted[$mid - 1] + $sorted[$mid]) / 2
            : $sorted[$mid];
    }

    private function sd(array $values): ?float
    {
        $n = count($values);
        if ($n < 2) return null;
        $mean = array_sum($values) / $n;
        $sq   = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $values));
        return round(sqrt($sq / ($n - 1)), 2);
    }

    private function avg(?float $a, ?float $b): ?float
    {
        if ($a !== null && $b !== null && $a > 0 && $b > 0) return ($a + $b) / 2;
        if ($a !== null && $a > 0) return $a;
        if ($b !== null && $b > 0) return $b;
        return null;
    }

    private function num(mixed $v): ?float
    {
        $s = trim((string)$v);
        if ($s === '' || $s === '.' || !is_numeric($s)) return null;
        $f = (float)$s;
        return $f > 0 ? $f : null;
    }
}
