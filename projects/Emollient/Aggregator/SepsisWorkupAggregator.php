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
 * ── Part A: babies with sepsis marked THIS period (unit = baby) ─────────────────
 *   Confirmed : >= 1 form in period with nss_sepsis = SEPSIS_IMPRESS_CONF
 *   Suspected : >= 1 form in period with nss_sepsis = SEPSIS_IMPRESS_SUSP
 *       $exclusive = true  → only babies never confirmed in the period
 *       $exclusive = false → a baby with both impressions is counted in both rows
 *
 *   Test window = from LOOKBACK days before the baby's first CONF/SUSP form in the
 *   period, up to date_to. Tests are read from ANY sepsis form in that window
 *   (a culture is often sent the day before sepsis is marked).
 *     CBC done      : nss_tests___sepsis_tests_cbc = 1
 *     CRP done      : nss_crp has a value other than 9999999999
 *     Culture done  : nss_blood_culture = 'Y', or a culture result is entered
 *     Culture result: from any form dated on/after the first culture in the window,
 *                     up to date_to. One per baby: Positive > Negative > other > awaited
 *
 * ── Part B: culture results pending from earlier weeks (unit = baby) ────────────
 *   Pending at start of period: the baby's LAST culture dated before date_from has no
 *   result on any form from that culture up to date_from. No age limit — a result
 *   overdue for weeks stays in the list until it is entered.
 *   Status this period: result on a form dated date_from..date_to → Positive /
 *   Negative / other; otherwise still awaited. Age = days from culture to date_to.
 *
 *   Limitation: if a result is typed into the ORIGINAL culture form (not a later
 *   day's form), its date is the culture date, so this report cannot tell it arrived
 *   this week — the baby simply stops being pending.
 *
 * Output (site-major; WeeklyMeetingAggregator converts to metric-major):
 *   by_site  [site][metric] + ['total']
 *   metrics  [metric] => [label, role, section, pct_of]
 *   per_baby one row per baby per counted group (Part A)  → Excel "Sepsis PerBaby"
 *   forms    every form in each counted baby's window     → Excel "Sepsis Forms"
 *   pending  one row per baby pending at start of period  → Excel "Sepsis Pending"
 *   skipped  ['undated' => n, 'no_site' => n]
 *   other_result_codes [code => forms]
 */
final class SepsisWorkupAggregator
{
    public const CONF = 'SEPSIS_IMPRESS_CONF';
    public const SUSP = 'SEPSIS_IMPRESS_SUSP';

    public const CRP_MISSING = '9999999999';

    public const CULTURE_POS = 'SEPSIS_CULTURERES_POS';
    public const CULTURE_NEG = 'SEPSIS_CULTURERES_NEG';

    public const CBC_COLUMN = 'nss_tests___sepsis_tests_cbc';

    /** Columns kept from each row (checkbox columns nss_tests___* are kept by prefix). */
    public const KEEP = [
        'record_id', 'redcap_event_name', 'nss_datetime', 'nss_hosp_code', 'nss_sepsis',
        'nss_crp', 'nss_blood_culture', 'nss_blood_culture_result',
    ];

    public const GROUPS = [
        'conf' => 'Confirmed Sepsis',
        'susp' => 'Suspected Sepsis',
    ];

    private const SUB = [
        'babies'       => 'Babies',
        'cbc'          => 'CBC done',
        'crp'          => 'CRP done',
        'culture'      => 'Blood Culture done',
        'cult_pos'     => 'Culture Positive',
        'cult_neg'     => 'Culture Negative',
        'cult_other'   => 'Culture — other result',
        'cult_pending' => 'Culture — result awaited',
        'no_test'      => 'No blood test done',
    ];

    private const RESULT_LABEL = [
        'cult_pos' => 'Positive', 'cult_neg' => 'Negative',
        'cult_other' => 'Other', 'cult_pending' => 'Awaited',
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

    /** Any filled-in sepsis screening form (all impressions are needed now). */
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
                    'cult_pos', 'cult_neg', 'cult_other', 'cult_pending' => 'sub2',
                    default => 'sub',
                };
                $pctOf = match ($role) {
                    'primary' => null,
                    'sub2'    => "sep_{$g}_culture",
                    default   => "sep_{$g}_babies",
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

        $sp = 'Culture results pending from earlier weeks (culture before period start, no result by then)';
        $meta += [
            'sep_pend_babies'     => ['label' => 'Babies with culture result pending at start of period', 'role' => 'primary', 'section' => $sp, 'pct_of' => null],
            'sep_pend_pos'        => ['label' => 'Result received this period: Positive',  'role' => 'sub2', 'section' => $sp, 'pct_of' => 'sep_pend_babies'],
            'sep_pend_neg'        => ['label' => 'Result received this period: Negative',  'role' => 'sub2', 'section' => $sp, 'pct_of' => 'sep_pend_babies'],
            'sep_pend_cult_other' => ['label' => 'Result received this period: other',     'role' => 'sub2', 'section' => $sp, 'pct_of' => 'sep_pend_babies'],
            'sep_pend_awaited'    => ['label' => 'Still awaited',                          'role' => 'sub',  'section' => $sp, 'pct_of' => 'sep_pend_babies'],
        ];
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

        $bySite  = [];
        $perBaby = [];
        $formRows = [];
        $pending = [];

        foreach ($byBaby as $rid => $forms) {
            usort($forms, fn($x, $y) => $x['_dt'] <=> $y['_dt']);

            // ── Part A: sepsis marked this period ───────────────────────────
            $impr = ['conf' => [], 'susp' => []];
            foreach ($forms as $f) {
                if ($fromStart && $f['_dt'] < $fromStart) continue;
                $v = trim((string)($f['nss_sepsis'] ?? ''));
                if ($v === self::CONF) $impr['conf'][] = $f;
                if ($v === self::SUSP) $impr['susp'][] = $f;
            }

            if ($impr['conf'] || $impr['susp']) {
                $first = min(array_map(fn($f) => $f['_dt'], array_merge($impr['conf'], $impr['susp'])));
                $firstForm = null;
                foreach (array_merge($impr['conf'], $impr['susp']) as $f) if ($f['_dt'] == $first) { $firstForm = $f; break; }
                $site = $this->siteOf($firstForm, $rid, $siteOfRecord);

                if ($site === '') {
                    $skipped['no_site']++;
                } elseif (!$this->siteFilter || in_array($site, $this->siteFilter, true)) {
                    $winStart = $first->setTime(0, 0, 0)->modify("-{$this->lookbackDays} days");
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
                        if ($w['culture']) $this->inc($bySite, $site, "sep_{$g}_" . $w['result']);
                        $noTest = !$w['cbc'] && !$w['crp'] && !$w['culture'];
                        if ($noTest) $this->inc($bySite, $site, "sep_{$g}_no_test");

                        $other = $g === 'conf' ? 'susp' : 'conf';
                        $perBaby[] = [
                            'record_id'        => (string)$rid,
                            'site'             => $site,
                            'counted_as'       => self::GROUPS[$g],
                            'days_with_impr'   => count($impr[$g]),
                            'first_impr_date'  => $impr[$g][0]['_dt']->format('Y-m-d'),
                            'last_impr_date'   => end($impr[$g])['_dt']->format('Y-m-d'),
                            'test_window'      => $winStart->format('Y-m-d') . ' to ' . ($toEnd ? $toEnd->format('Y-m-d') : 'today'),
                            'cbc_done'         => $w['cbc'] ? 'Y' : 'N',
                            'crp_done'         => $w['crp'] ? 'Y' : 'N',
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
                            'record_id'      => (string)$rid,
                            'site'           => $site,
                            'event'          => (string)($f['redcap_event_name'] ?? ''),
                            'nss_datetime'   => $f['_dt']->format('Y-m-d H:i'),
                            'impression'     => $this->impressionLabel($f),
                            'cbc'            => RedcapFieldReader::isChecked($f[self::CBC_COLUMN] ?? '') ? 'Y' : 'N',
                            'crp_value'      => trim((string)($f['nss_crp'] ?? '')),
                            'blood_culture'  => trim((string)($f['nss_blood_culture'] ?? '')),
                            'culture_result' => $res === '' ? '' : RedcapFieldReader::label($this->dict, 'nss_blood_culture_result', $res),
                            'counted_as'     => implode(' + ', array_map(fn($g) => self::GROUPS[$g], $groups)),
                        ];
                    }
                }
            }

            // ── Part B: culture result pending from before the period ────────
            if ($fromStart) {
                $before = array_values(array_filter($forms, fn($f) => $f['_dt'] < $fromStart));
                $lastC  = null;
                foreach ($before as $f) if ($this->isCulture($f)) $lastC = $f;
                if ($lastC === null) continue;

                $resolved = false;
                foreach ($before as $f) {
                    if ($f['_dt'] >= $lastC['_dt'] && $this->resultCode($f) !== '') { $resolved = true; break; }
                }
                if ($resolved) continue;

                $site = $this->siteOf($lastC, $rid, $siteOfRecord);
                if ($site === '') { $skipped['no_site']++; continue; }
                if ($this->siteFilter && !in_array($site, $this->siteFilter, true)) continue;

                $status = 'cult_pending'; $resDt = null;
                foreach ($forms as $f) {
                    if ($f['_dt'] < $fromStart) continue;
                    $code = $this->resultCode($f);
                    if ($code === '') continue;
                    $res = $this->classify($code, $otherCodes);
                    if (self::RANK[$res] > self::RANK[$status]) { $status = $res; $resDt = $f['_dt']; }
                }

                $this->inc($bySite, $site, 'sep_pend_babies');
                $this->inc($bySite, $site, match ($status) {
                    'cult_pos' => 'sep_pend_pos', 'cult_neg' => 'sep_pend_neg',
                    'cult_other' => 'sep_pend_cult_other', default => 'sep_pend_awaited',
                });

                $ageTo = $toEnd ?? new \DateTimeImmutable();
                $pending[] = [
                    'record_id'          => (string)$rid,
                    'site'               => $site,
                    'culture_date'       => $lastC['_dt']->format('Y-m-d'),
                    'culture_event'      => (string)($lastC['redcap_event_name'] ?? ''),
                    'impression_then'    => $this->impressionLabel($lastC),
                    'age_days'           => $lastC['_dt']->setTime(0, 0)->diff($ageTo->setTime(0, 0))->days,
                    'status'             => $status === 'cult_pending' ? 'Still awaited' : 'Received: ' . self::RESULT_LABEL[$status],
                    'result_form_date'   => $resDt?->format('Y-m-d') ?? '',
                ];
            }
        }

        usort($pending, fn($a, $b) => $b['age_days'] <=> $a['age_days'] ?: strnatcmp($a['record_id'], $b['record_id']));
        $meta = self::metricMeta($this->exclusive, $this->lookbackDays);

        return [
            'by_site'            => self::finalise($bySite, $meta, $this->siteFilter),
            'metrics'            => $meta,
            'per_baby'           => $perBaby,
            'forms'              => $formRows,
            'pending'            => $pending,
            'skipped'            => $skipped,
            'other_result_codes' => $otherCodes,
        ];
    }

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

    /** Tests across a baby's window + one culture result from on/after the first culture. */
    private function workup(array $forms, array &$otherCodes): array
    {
        $w = ['cbc' => false, 'crp' => false, 'culture' => false, 'result' => 'cult_pending',
              'culture_dt' => null, 'result_dt' => null];

        foreach ($forms as $f) {
            if (RedcapFieldReader::isChecked($f[self::CBC_COLUMN] ?? '')) $w['cbc'] = true;

            $crp = trim((string)($f['nss_crp'] ?? ''));
            if ($crp !== '' && $crp !== self::CRP_MISSING) $w['crp'] = true;

            if ($this->isCulture($f)) {
                $w['culture'] = true;
                $w['culture_dt'] ??= $f['_dt'];
            }
            // Result counts from the first culture onwards (forms are date-sorted)
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

    private function isCulture(array $f): bool
    {
        return trim((string)($f['nss_blood_culture'] ?? '')) === 'Y' || $this->resultCode($f) !== '';
    }

    private function resultCode(array $f): string
    {
        return trim((string)($f['nss_blood_culture_result'] ?? ''));
    }

    private function classify(string $code, array &$otherCodes): string
    {
        return match (true) {
            $code === self::CULTURE_POS => 'cult_pos',
            $code === self::CULTURE_NEG => 'cult_neg',
            default => (function () use ($code, &$otherCodes) { $otherCodes[$code] = ($otherCodes[$code] ?? 0) + 1; return 'cult_other'; })(),
        };
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
                              ? ucfirst(strtolower(substr($v, 15))) . ' (' . $v . ')'   // e.g. Prob (SEPSIS_IMPRESS_PROB)
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
