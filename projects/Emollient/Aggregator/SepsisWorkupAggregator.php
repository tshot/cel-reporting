<?php

namespace CEL\Projects\Emollient\Aggregator;

/**
 * SepsisWorkupAggregator
 *
 * Helper used by WeeklyMeetingAggregator — Section 4: Sepsis & Blood Workup.
 *
 * Source : neonatal_sepsis_screening (one form per baby per study day), ALL forms of
 *          the babies fetched (any impression), dated up to date_to. Forms dated after
 *          date_to are ignored, so the report reads "as of date_to" whenever it is run.
 * Site   : nss_hosp_code, falling back to the baby's enr_hosp_code
 *
 * Definitions used throughout — a test is DONE when it is ticked; its RESULT is the value
 *   CBC done     : nss_tests___sepsis_tests_cbc = 1   (or a TLC number present)
 *   TLC result   : nss_tlc is a number other than 9999999999 (NI / blank = missing)
 *   CRP done     : nss_tests___sepsis_tests_crp = 1   (or a CRP number present)
 *   CRP result   : nss_crp is a number other than 9999999999 (NI / blank = missing)
 *   Culture done : nss_blood_culture = 'Y'            (or a Positive / Negative result)
 *   Culture result: nss_blood_culture_result = Positive or Negative. NI and blank are
 *                  NOT a result (the report is still missing). Any other code is
 *                  counted as "other result" and listed in the notes.
 *   Window       : from LOOKBACK days before the baby's first impression form that
 *                  defines the case, up to date_to.
 *
 * ── Part A: babies with sepsis marked THIS period (unit = baby) ─────────────────
 *   Confirmed : >= 1 form in period with nss_sepsis = SEPSIS_IMPRESS_CONF
 *   Suspected : >= 1 form in period with nss_sepsis = SEPSIS_IMPRESS_SUSP
 *       $exclusive = true  → only babies never confirmed in the period
 *       $exclusive = false → a baby with both impressions is counted in both rows
 *   Window starts LOOKBACK days before the first CONF/SUSP form in the period.
 *   Rows: CBC done (+ TLC result missing), CRP done (+ CRP result missing),
 *         culture done (+ result), no blood test.
 *
 * ── Part B: earlier cases whose workup is still missing (unit = baby) ───────────
 *   "Earlier" = impression on a form dated before date_from, however long ago.
 *   Confirmed earlier (first CONF form before date_from; window from it − LOOKBACK):
 *     blood culture missing at period start : no culture in the window before date_from
 *         → recorded this period            : culture on a form dated in the period
 *         → still missing
 *     culture result missing at period start: culture done before date_from, but no
 *                                             Positive / Negative result before date_from
 *         → received this period: Positive / Negative / other
 *         → still missing
 *   Suspected earlier (SUSP before date_from, never CONF before date_from; window from
 *   the first SUSP − LOOKBACK), for CBC and for CRP separately:
 *     test not done at period start       → done this period / still not done
 *     result missing at period start      : test done before date_from, no number on or
 *                                           after it before date_from
 *         → received this period / still missing
 *
 *   Limitation: a value typed into an OLD form (dated before date_from) cannot be told
 *   apart from one entered on time, so the baby drops out of the backlog instead of
 *   showing as "recorded / received this period".
 *
 * Output (site-major; WeeklyMeetingAggregator converts to metric-major):
 *   by_site  [site][metric] + ['total']
 *   metrics  [metric] => [label, role, section, pct_of]
 *   per_baby one row per baby per counted group (Part A)   → Excel "Sepsis PerBaby"
 *   forms    every form in each counted baby's window      → Excel "Sepsis Forms"
 *   backlog  one row per baby per missing item (Part B)    → Excel "Sepsis Backlog"
 *   skipped  ['undated' => n, 'no_site' => n]
 *   other_result_codes [code => forms]
 */
final class SepsisWorkupAggregator
{
    public const CONF = 'SEPSIS_IMPRESS_CONF';
    public const SUSP = 'SEPSIS_IMPRESS_SUSP';

    public const MISSING_CODE = '9999999999';   // CRP / TLC "not available"

    public const CULTURE_POS = 'SEPSIS_CULTURERES_POS';
    public const CULTURE_NEG = 'SEPSIS_CULTURERES_NEG';
    public const NO_RESULT   = ['', 'NI'];       // result field values that mean "missing"

    public const CBC_COLUMN = 'nss_tests___sepsis_tests_cbc';

    /** Blood tests: tick box = test done, value field = its result. */
    public const LAB_TESTS = [
        'cbc' => ['test' => 'CBC', 'box' => 'nss_tests___sepsis_tests_cbc', 'value' => 'nss_tlc', 'result' => 'TLC'],
        'crp' => ['test' => 'CRP', 'box' => 'nss_tests___sepsis_tests_crp', 'value' => 'nss_crp', 'result' => 'CRP'],
    ];

    /** Columns kept from each row (checkbox columns nss_tests___* are kept by prefix). */
    public const KEEP = [
        'record_id', 'redcap_event_name', 'nss_datetime', 'nss_hosp_code', 'nss_sepsis',
        'nss_crp', 'nss_tlc', 'nss_blood_culture', 'nss_blood_culture_result',
    ];

    public const GROUPS = [
        'conf' => 'Confirmed Sepsis',
        'susp' => 'Suspected Sepsis',
    ];

    private const SUB = [
        'babies'       => 'Babies',
        'cbc'          => 'CBC done',
        'cbc_res_miss' => 'TLC result missing (blank / NI)',
        'crp'          => 'CRP done',
        'crp_res_miss' => 'CRP result missing (blank / NI)',
        'culture'      => 'Blood Culture done',
        'cult_pos'     => 'Culture Positive',
        'cult_neg'     => 'Culture Negative',
        'cult_other'   => 'Culture — other result',
        'cult_pending' => 'Culture — result missing (blank / NI)',
        'no_test'      => 'No blood test done',
    ];

    private const RESULT_LABEL = [
        'cult_pos' => 'Positive', 'cult_neg' => 'Negative',
        'cult_other' => 'Other', 'cult_pending' => 'Missing',
    ];

    private const RANK = ['cult_pending' => 0, 'cult_other' => 1, 'cult_neg' => 2, 'cult_pos' => 3];

    public function __construct(
        private ?\DateTimeInterface $from = null,
        private ?\DateTimeInterface $to = null,
        private array $siteFilter = [],
        private bool $exclusive = true,
        private array $dict = [],
        private int $lookbackDays = 2,
    ) {}

    /** Any filled-in sepsis screening form (all impressions are needed). */
    public static function wants(array $row): bool
    {
        return trim((string)($row['nss_datetime'] ?? '')) !== ''
            || trim((string)($row['nss_sepsis'] ?? '')) !== '';
    }

    /** Reduce a row to the columns this aggregator reads (saves memory on big pulls). */
    public static function slim(array $row): array
    {
        $out = array_intersect_key($row, array_flip(self::KEEP));
        foreach ($row as $k => $v) {
            if (str_starts_with((string)$k, 'nss_tests___')) $out[$k] = $v;
        }
        return $out;
    }

    /** Display metadata, in render order. role: primary | sub | sub2 */
    public static function metricMeta(bool $exclusive = true, int $lookbackDays = 2): array
    {
        $sections = [
            'conf' => 'Confirmed Sepsis this period (nss_sepsis = SEPSIS_IMPRESS_CONF)',
            'susp' => 'Suspected Sepsis this period (nss_sepsis = SEPSIS_IMPRESS_SUSP'
                    . ($exclusive ? '; never confirmed in period' : '') . ')',
        ];
        $meta = [];
        foreach (self::GROUPS as $g => $groupLabel) {
            foreach (self::SUB as $k => $label) {
                $role = match ($k) {
                    'babies' => 'primary',
                    'cult_pos', 'cult_neg', 'cult_other', 'cult_pending', 'cbc_res_miss', 'crp_res_miss' => 'sub2',
                    default => 'sub',
                };
                $pctOf = match ($k) {
                    'babies'       => null,
                    'cbc_res_miss' => "sep_{$g}_cbc",
                    'crp_res_miss' => "sep_{$g}_crp",
                    'cult_pos', 'cult_neg', 'cult_other', 'cult_pending' => "sep_{$g}_culture",
                    default        => "sep_{$g}_babies",
                };
                if ($k === 'babies') $label = 'Babies with ' . strtolower($groupLabel);
                $meta["sep_{$g}_{$k}"] = [
                    'label'   => $label,
                    'role'    => $role,
                    'section' => $sections[$g],
                    'pct_of'  => $pctOf,
                ];
            }
        }

        $sc = 'Confirmed earlier (before period start): blood culture / culture result still missing';
        $ss = 'Suspected earlier (before period start, never confirmed): CBC / CRP test or result still missing';
        $row = fn($label, $role, $sec, $pct) => ['label' => $label, 'role' => $role, 'section' => $sec, 'pct_of' => $pct];
        $meta += [
            'sep_bl_cult_missing'  => $row('Blood culture missing at period start',         'primary', $sc, null),
            'sep_bl_cult_rec'      => $row('Culture recorded this period',                  'sub2', $sc, 'sep_bl_cult_missing'),
            'sep_bl_cult_still'    => $row('Still missing',                                 'sub2', $sc, 'sep_bl_cult_missing'),
            'sep_bl_res_missing'   => $row('Culture result missing at period start (blank / NI)', 'primary', $sc, null),
            'sep_bl_res_pos'       => $row('Result received this period: Positive',         'sub2', $sc, 'sep_bl_res_missing'),
            'sep_bl_res_neg'       => $row('Result received this period: Negative',         'sub2', $sc, 'sep_bl_res_missing'),
            'sep_bl_res_cult_other'=> $row('Result received this period: other',            'sub2', $sc, 'sep_bl_res_missing'),
            'sep_bl_res_still'     => $row('Still missing',                                 'sub2', $sc, 'sep_bl_res_missing'),
        ];
        foreach (self::LAB_TESTS as $t => $d) {
            $meta += [
                "sep_bl_{$t}_notdone"   => $row("{$d['test']} not done at period start",                     'primary', $ss, null),
                "sep_bl_{$t}_done_rec"  => $row("{$d['test']} done this period",                             'sub2', $ss, "sep_bl_{$t}_notdone"),
                "sep_bl_{$t}_nd_still"  => $row('Still not done',                                            'sub2', $ss, "sep_bl_{$t}_notdone"),
                "sep_bl_{$t}_resmiss"   => $row("{$d['result']} result missing at period start ({$d['test']} done, blank / NI)", 'primary', $ss, null),
                "sep_bl_{$t}_res_rec"   => $row("{$d['result']} result received this period",                'sub2', $ss, "sep_bl_{$t}_resmiss"),
                "sep_bl_{$t}_res_still" => $row('Still missing',                                             'sub2', $ss, "sep_bl_{$t}_resmiss"),
            ];
        }
        return $meta;
    }

    /**
     * @param iterable $rows         Sepsis screening rows (raw values), any impression.
     * @param array    $siteOfRecord record_id => enr_hosp_code (fallback when nss_hosp_code is blank)
     */
    public function aggregate(iterable $rows, array $siteOfRecord = []): array
    {
        $skipped    = ['undated' => 0, 'no_site' => 0];
        $otherCodes = [];
        $fromStart  = $this->from ? \DateTimeImmutable::createFromInterface($this->from)->setTime(0, 0, 0) : null;
        $toEnd      = $this->to   ? \DateTimeImmutable::createFromInterface($this->to)->setTime(23, 59, 59) : null;

        // ── Collect every form per baby, up to date_to ─────────────────────
        $byBaby = [];
        foreach ($rows as $r) {
            if (!self::wants($r)) continue;
            $dt = RedcapFieldReader::date($r['nss_datetime'] ?? '');
            if ($dt === null) {
                if (in_array(trim((string)($r['nss_sepsis'] ?? '')), [self::CONF, self::SUSP], true)) $skipped['undated']++;
                continue;
            }
            if ($toEnd && $dt > $toEnd) continue;
            $r['_dt'] = $dt;
            $byBaby[(string)$r['record_id']][] = $r;
        }
        uksort($byBaby, 'strnatcmp');

        $bySite   = [];
        $perBaby  = [];
        $formRows = [];
        $backlog  = [];

        foreach ($byBaby as $rid => $forms) {
            usort($forms, fn($x, $y) => $x['_dt'] <=> $y['_dt']);
            $this->partA($rid, $forms, $fromStart, $toEnd, $siteOfRecord, $bySite, $perBaby, $formRows, $skipped, $otherCodes);
            if ($fromStart) {
                $this->partB($rid, $forms, $fromStart, $toEnd, $siteOfRecord, $bySite, $backlog, $skipped, $otherCodes);
            }
        }

        usort($backlog, fn($a, $b) => $b['days_since_impression'] <=> $a['days_since_impression']
                                     ?: strnatcmp($a['record_id'], $b['record_id']));
        $meta = self::metricMeta($this->exclusive, $this->lookbackDays);

        return [
            'by_site'            => self::finalise($bySite, $meta, $this->siteFilter),
            'metrics'            => $meta,
            'per_baby'           => $perBaby,
            'forms'              => $formRows,
            'backlog'            => $backlog,
            'skipped'            => $skipped,
            'other_result_codes' => $otherCodes,
        ];
    }

    // =========================================================================
    // Part A — sepsis marked this period
    // =========================================================================

    private function partA(string $rid, array $forms, ?\DateTimeImmutable $fromStart, ?\DateTimeImmutable $toEnd,
                           array $siteOfRecord, array &$bySite, array &$perBaby, array &$formRows,
                           array &$skipped, array &$otherCodes): void
    {
        $impr = ['conf' => [], 'susp' => []];
        foreach ($forms as $f) {
            if ($fromStart && $f['_dt'] < $fromStart) continue;
            $v = trim((string)($f['nss_sepsis'] ?? ''));
            if ($v === self::CONF) $impr['conf'][] = $f;
            if ($v === self::SUSP) $impr['susp'][] = $f;
        }
        if (!$impr['conf'] && !$impr['susp']) return;

        $firstForm = $this->earliest(array_merge($impr['conf'], $impr['susp']));
        $site = $this->siteOf($firstForm, $rid, $siteOfRecord);
        if ($site === '') { $skipped['no_site']++; return; }
        if ($this->siteFilter && !in_array($site, $this->siteFilter, true)) return;

        $winStart = $this->windowStart($firstForm);
        $window   = array_values(array_filter($forms, fn($f) => $f['_dt'] >= $winStart));
        $w        = $this->workup($window, $otherCodes);

        $groups = [];
        if ($impr['conf']) $groups[] = 'conf';
        if ($impr['susp'] && (!$this->exclusive || !$impr['conf'])) $groups[] = 'susp';

        foreach ($groups as $g) {
            $this->inc($bySite, $site, "sep_{$g}_babies");
            foreach (['cbc', 'crp', 'culture'] as $t) {
                if ($w[$t]) $this->inc($bySite, $site, "sep_{$g}_{$t}");
            }
            foreach (self::LAB_TESTS as $t => $_) {
                if ($w[$t] && !$w["{$t}_res"]) $this->inc($bySite, $site, "sep_{$g}_{$t}_res_miss");
            }
            if ($w['culture']) $this->inc($bySite, $site, "sep_{$g}_" . $w['result']);
            $noTest = !$w['cbc'] && !$w['crp'] && !$w['culture'];
            if ($noTest) $this->inc($bySite, $site, "sep_{$g}_no_test");

            $other = $g === 'conf' ? 'susp' : 'conf';
            $perBaby[] = [
                'record_id'        => $rid,
                'site'             => $site,
                'counted_as'       => self::GROUPS[$g],
                'days_with_impr'   => count($impr[$g]),
                'first_impr_date'  => $impr[$g][0]['_dt']->format('Y-m-d'),
                'last_impr_date'   => end($impr[$g])['_dt']->format('Y-m-d'),
                'test_window'      => $winStart->format('Y-m-d') . ' to ' . ($toEnd ? $toEnd->format('Y-m-d') : 'today'),
                'cbc_done'         => $w['cbc'] ? 'Y' : 'N',
                'tlc_result'       => $w['cbc'] ? ($w['cbc_res'] ? 'Y' : 'missing') : '',
                'crp_done'         => $w['crp'] ? 'Y' : 'N',
                'crp_result'       => $w['crp'] ? ($w['crp_res'] ? 'Y' : 'missing') : '',
                'culture_done'     => $w['culture'] ? 'Y' : 'N',
                'culture_date'     => $w['culture_dt']?->format('Y-m-d') ?? '',
                'culture_result'   => $w['culture'] ? self::RESULT_LABEL[$w['result']] : '',
                'result_form_date' => $w['result_dt']?->format('Y-m-d') ?? '',
                'no_test_done'     => $noTest ? 'Y' : 'N',
                'other_impression' => empty($impr[$other]) ? '' : self::GROUPS[$other] . ' on ' . count($impr[$other]) . ' day(s)',
            ];
        }

        foreach ($window as $f) {
            $res = trim((string)($f['nss_blood_culture_result'] ?? ''));
            $formRows[] = [
                'record_id'      => $rid,
                'site'           => $site,
                'event'          => (string)($f['redcap_event_name'] ?? ''),
                'nss_datetime'   => $f['_dt']->format('Y-m-d H:i'),
                'impression'     => $this->impressionLabel($f),
                'cbc_ticked'     => RedcapFieldReader::isChecked($f[self::LAB_TESTS['cbc']['box']] ?? '') ? 'Y' : 'N',
                'tlc_value'      => trim((string)($f['nss_tlc'] ?? '')),
                'crp_ticked'     => RedcapFieldReader::isChecked($f[self::LAB_TESTS['crp']['box']] ?? '') ? 'Y' : 'N',
                'crp_value'      => trim((string)($f['nss_crp'] ?? '')),
                'blood_culture'  => trim((string)($f['nss_blood_culture'] ?? '')),
                'culture_result' => $res === '' ? '' : RedcapFieldReader::label($this->dict, 'nss_blood_culture_result', $res),
                'counted_as'     => implode(' + ', array_map(fn($g) => self::GROUPS[$g], $groups)),
            ];
        }
    }

    // =========================================================================
    // Part B — earlier cases whose workup is still missing
    // =========================================================================

    private function partB(string $rid, array $forms, \DateTimeImmutable $fromStart, ?\DateTimeImmutable $toEnd,
                           array $siteOfRecord, array &$bySite, array &$backlog,
                           array &$skipped, array &$otherCodes): void
    {
        $before = array_values(array_filter($forms, fn($f) => $f['_dt'] < $fromStart));
        $firstConf = null; $firstSusp = null;
        foreach ($before as $f) {
            $v = trim((string)($f['nss_sepsis'] ?? ''));
            if ($v === self::CONF && !$firstConf) $firstConf = $f;
            if ($v === self::SUSP && !$firstSusp) $firstSusp = $f;
        }
        if (!$firstConf && !$firstSusp) return;

        $caseForm = $firstConf ?? $firstSusp;
        $site = $this->siteOf($caseForm, $rid, $siteOfRecord);
        if ($site === '') { $skipped['no_site']++; return; }
        if ($this->siteFilter && !in_array($site, $this->siteFilter, true)) return;

        $winStart  = $this->windowStart($caseForm);
        $winBefore = array_values(array_filter($before, fn($f) => $f['_dt'] >= $winStart));
        $inPeriod  = array_values(array_filter($forms, fn($f) => $f['_dt'] >= $fromStart));
        $ref       = ($toEnd ?? new \DateTimeImmutable())->setTime(0, 0);
        $base = [
            'record_id'             => $rid,
            'site'                  => $site,
            'case'                  => $firstConf ? 'Confirmed earlier' : 'Suspected earlier',
            'first_impression_date' => $caseForm['_dt']->format('Y-m-d'),
            'days_since_impression' => $caseForm['_dt']->setTime(0, 0)->diff($ref)->days,
            'window_start'          => $winStart->format('Y-m-d'),
        ];

        if ($firstConf) {
            // ── Blood culture ────────────────────────────────────────────────
            $cultBefore = null;
            foreach ($winBefore as $f) if ($this->isCulture($f)) { $cultBefore = $f; break; }

            if ($cultBefore === null) {
                $this->inc($bySite, $site, 'sep_bl_cult_missing');
                $rec = null;
                foreach ($inPeriod as $f) if ($this->isCulture($f)) { $rec = $f; break; }
                $this->inc($bySite, $site, $rec ? 'sep_bl_cult_rec' : 'sep_bl_cult_still');
                $backlog[] = $base + [
                    'missing'      => 'Blood culture',
                    'status'       => $rec ? 'Recorded this period' : 'Still missing',
                    'on_form_date' => $rec ? $rec['_dt']->format('Y-m-d') : '',
                ];
            } else {
                // ── Culture result ───────────────────────────────────────────
                $resolved = false;
                foreach ($winBefore as $f) {
                    if ($f['_dt'] >= $cultBefore['_dt'] && $this->resultCode($f) !== '') { $resolved = true; break; }
                }
                if (!$resolved) {
                    $this->inc($bySite, $site, 'sep_bl_res_missing');
                    $status = 'cult_pending'; $resDt = null;
                    foreach ($inPeriod as $f) {
                        $code = $this->resultCode($f);
                        if ($code === '') continue;
                        $res = $this->classify($code, $otherCodes);
                        if (self::RANK[$res] > self::RANK[$status]) { $status = $res; $resDt = $f['_dt']; }
                    }
                    $this->inc($bySite, $site, match ($status) {
                        'cult_pos' => 'sep_bl_res_pos', 'cult_neg' => 'sep_bl_res_neg',
                        'cult_other' => 'sep_bl_res_cult_other', default => 'sep_bl_res_still',
                    });
                    $backlog[] = $base + [
                        'missing'      => 'Culture result (culture ' . $cultBefore['_dt']->format('Y-m-d') . ')',
                        'status'       => $status === 'cult_pending' ? 'Still missing' : 'Received this period: ' . self::RESULT_LABEL[$status],
                        'on_form_date' => $resDt?->format('Y-m-d') ?? '',
                    ];
                }
            }
            return;
        }

        // ── Suspected earlier, never confirmed before the period: CBC and CRP ──
        foreach (self::LAB_TESTS as $t => $d) {
            $doneBefore = null;
            foreach ($winBefore as $f) if ($this->testDone($f, $t)) { $doneBefore = $f; break; }

            if ($doneBefore === null) {
                $this->inc($bySite, $site, "sep_bl_{$t}_notdone");
                $rec = null;
                foreach ($inPeriod as $f) if ($this->testDone($f, $t)) { $rec = $f; break; }
                $this->inc($bySite, $site, $rec ? "sep_bl_{$t}_done_rec" : "sep_bl_{$t}_nd_still");
                $backlog[] = $base + [
                    'missing'      => "{$d['test']} test",
                    'status'       => $rec ? 'Done this period' : 'Still not done',
                    'on_form_date' => $rec ? $rec['_dt']->format('Y-m-d') : '',
                ];
                continue;
            }

            $resolved = false;
            foreach ($winBefore as $f) {
                if ($f['_dt'] >= $doneBefore['_dt'] && $this->hasValue($f, $d['value'])) { $resolved = true; break; }
            }
            if ($resolved) continue;

            $this->inc($bySite, $site, "sep_bl_{$t}_resmiss");
            $rec = null;
            foreach ($inPeriod as $f) if ($this->hasValue($f, $d['value'])) { $rec = $f; break; }
            $this->inc($bySite, $site, $rec ? "sep_bl_{$t}_res_rec" : "sep_bl_{$t}_res_still");
            $backlog[] = $base + [
                'missing'      => "{$d['result']} result ({$d['test']} " . $doneBefore['_dt']->format('Y-m-d') . ')',
                'status'       => $rec ? 'Received this period' : 'Still missing',
                'on_form_date' => $rec ? $rec['_dt']->format('Y-m-d') : '',
            ];
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Zero-fill every site for every metric and add a 'total' column. */
    public static function finalise(array $bySite, array $meta, array $siteFilter = []): array
    {
        foreach ($siteFilter as $s) $bySite[$s] ??= [];
        $total = array_fill_keys(array_keys($meta), 0);
        foreach ($bySite as $s => $vals) {
            foreach ($meta as $m => $_) {
                $bySite[$s][$m] = $vals[$m] ?? 0;
                $total[$m] += $bySite[$s][$m];
            }
        }
        $bySite['total'] = $total;
        return $bySite;
    }

    /** Tests across a window + one culture result from on/after the first culture. */
    private function workup(array $forms, array &$otherCodes): array
    {
        $w = ['cbc' => false, 'cbc_res' => false, 'crp' => false, 'crp_res' => false, 'culture' => false,
              'result' => 'cult_pending', 'culture_dt' => null, 'result_dt' => null];

        foreach ($forms as $f) {
            foreach (self::LAB_TESTS as $t => $d) {
                if ($this->testDone($f, $t)) $w[$t] = true;
                if ($this->hasValue($f, $d['value'])) $w["{$t}_res"] = true;
            }

            if ($this->isCulture($f)) {
                $w['culture'] = true;
                $w['culture_dt'] ??= $f['_dt'];
            }
            if ($w['culture']) {
                $code = $this->resultCode($f);
                if ($code !== '') {
                    $res = $this->classify($code, $otherCodes);
                    if (self::RANK[$res] > self::RANK[$w['result']]) { $w['result'] = $res; $w['result_dt'] = $f['_dt']; }
                }
            }
        }
        return $w;
    }

    /** A lab result is present when it is a number other than the missing code (NI / blank = missing). */
    private function hasValue(array $f, string $field): bool
    {
        $v = trim((string)($f[$field] ?? ''));
        return is_numeric($v) && $v !== self::MISSING_CODE && (float)$v !== 9999999999.0;
    }

    /** Test done = ticked on the form, or (data-entry slip) a result entered without the tick. */
    private function testDone(array $f, string $t): bool
    {
        $d = self::LAB_TESTS[$t];
        return RedcapFieldReader::isChecked($f[$d['box']] ?? '') || $this->hasValue($f, $d['value']);
    }

    private function isCulture(array $f): bool
    {
        return trim((string)($f['nss_blood_culture'] ?? '')) === 'Y' || $this->resultCode($f) !== '';
    }

    /** The result code, or '' when there is no result (blank or NI). */
    private function resultCode(array $f): string
    {
        $v = trim((string)($f['nss_blood_culture_result'] ?? ''));
        return in_array(strtoupper($v), self::NO_RESULT, true) ? '' : $v;
    }

    private function classify(string $code, array &$otherCodes): string
    {
        if ($code === self::CULTURE_POS) return 'cult_pos';
        if ($code === self::CULTURE_NEG) return 'cult_neg';
        $otherCodes[$code] = ($otherCodes[$code] ?? 0) + 1;
        return 'cult_other';
    }

    private function earliest(array $forms): array
    {
        usort($forms, fn($x, $y) => $x['_dt'] <=> $y['_dt']);
        return $forms[0];
    }

    private function windowStart(array $form): \DateTimeImmutable
    {
        return $form['_dt']->setTime(0, 0, 0)->modify("-{$this->lookbackDays} days");
    }

    private function impressionLabel(array $f): string
    {
        $v = trim((string)($f['nss_sepsis'] ?? ''));
        return match ($v) {
            self::CONF => 'Confirmed',
            self::SUSP => 'Suspected',
            ''         => '',
            default    => $this->dict['nss_sepsis'][$v]
                          ?? (str_starts_with($v, 'SEPSIS_IMPRESS_')
                              ? ucfirst(strtolower(substr($v, 15))) . ' (' . $v . ')'
                              : $v),
        };
    }

    private function siteOf(?array $f, string $rid, array $siteOfRecord): string
    {
        return trim((string)($f['nss_hosp_code'] ?? '')) ?: (string)($siteOfRecord[$rid] ?? '');
    }

    private function inc(array &$bySite, string $site, string $metric): void
    {
        $bySite[$site][$metric] = ($bySite[$site][$metric] ?? 0) + 1;
    }
}
