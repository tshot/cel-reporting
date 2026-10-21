<?php

namespace CEL\Projects\Emollient\Aggregator;

/**
 * SepsisWorkupAggregator
 *
 * Helper used by WeeklyMeetingAggregator — Section 4: Sepsis & Blood Workup.
 *
 * Source : neonatal_sepsis_screening (one form per baby per study day)
 * Date   : nss_datetime (form must fall inside the report period)
 * Site   : nss_hosp_code, falling back to the baby's enr_hosp_code
 *
 * Unit of count = BABY, not form.
 *   - Confirmed Sepsis : >= 1 form in period with nss_sepsis = SEPSIS_IMPRESS_CONF
 *   - Suspected Sepsis : >= 1 form in period with nss_sepsis = SEPSIS_IMPRESS_SUSP
 *       $exclusive = true  → only babies never confirmed in the period, so
 *                            Confirmed + Suspected = distinct babies with sepsis
 *       $exclusive = false → a baby with both impressions is counted in both
 *
 * Tests are looked at ON THE FORMS CARRYING THAT IMPRESSION; a baby counts once per test.
 *   - CBC done          : nss_tests___sepsis_tests_cbc = 1
 *   - CRP done          : nss_crp has a value other than the missing code 9999999999
 *   - Blood culture done: nss_blood_culture = 'Y' (exact Y; N / NI / blank are not done)
 *   - Culture result    : nss_blood_culture_result, one per baby by precedence
 *                         Positive > Negative > any other code > awaited (blank)
 *   - No test done      : none of CBC / CRP / culture on those forms
 *
 * Output (site-major; WeeklyMeetingAggregator converts to the report's metric-major shape):
 *   by_site  [site][metric] + ['total']
 *   metrics  [metric] => [label, role, section, pct_of]
 *   per_baby one row per baby per counted group      → Excel "Sepsis PerBaby"
 *   forms    one row per CONF/SUSP form in period    → Excel "Sepsis Forms"
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

    public function __construct(
        private ?\DateTimeInterface $from = null,
        private ?\DateTimeInterface $to = null,
        private array $siteFilter = [],
        private bool $exclusive = true,
        private array $dict = [],
    ) {}

    /** Is this row a sepsis-impression form worth keeping? */
    public static function wants(array $row): bool
    {
        $v = trim((string)($row['nss_sepsis'] ?? ''));
        return $v === self::CONF || $v === self::SUSP;
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
    public static function metricMeta(bool $exclusive = true): array
    {
        $sections = [
            'conf' => 'Confirmed Sepsis (nss_sepsis = SEPSIS_IMPRESS_CONF)',
            'susp' => 'Suspected Sepsis (nss_sepsis = SEPSIS_IMPRESS_SUSP'
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
        return $meta;
    }

    /**
     * @param iterable $rows         Sepsis screening rows (raw values).
     * @param array    $siteOfRecord record_id => enr_hosp_code (fallback when nss_hosp_code is blank)
     */
    public function aggregate(iterable $rows, array $siteOfRecord = []): array
    {
        $skipped    = ['undated' => 0, 'no_site' => 0];
        $otherCodes = [];
        $babies     = [];   // record_id => ['site' => ..., 'conf' => [forms], 'susp' => [forms]]

        foreach ($rows as $r) {
            $impr = trim((string)($r['nss_sepsis'] ?? ''));
            $g = match ($impr) { self::CONF => 'conf', self::SUSP => 'susp', default => null };
            if ($g === null) continue;

            $dt = RedcapFieldReader::date($r['nss_datetime'] ?? '');
            if ($dt === null) { $skipped['undated']++; continue; }
            if (!RedcapFieldReader::inPeriod($dt, $this->from, $this->to)) continue;

            $rid  = (string)$r['record_id'];
            $site = trim((string)($r['nss_hosp_code'] ?? '')) ?: (string)($siteOfRecord[$rid] ?? '');
            if ($site === '') { $skipped['no_site']++; continue; }
            if ($this->siteFilter && !in_array($site, $this->siteFilter, true)) continue;

            $r['_dt'] = $dt;
            $babies[$rid]['site'] ??= $site;
            $babies[$rid][$g][] = $r;
        }
        uksort($babies, 'strnatcmp');

        $bySite  = [];
        $perBaby = [];
        $forms   = [];

        foreach ($babies as $rid => $b) {
            $site   = $b['site'];
            $groups = [];
            if (!empty($b['conf'])) $groups[] = 'conf';
            if (!empty($b['susp']) && (!$this->exclusive || empty($b['conf']))) $groups[] = 'susp';

            foreach ($groups as $g) {
                usort($b[$g], fn($x, $y) => $x['_dt'] <=> $y['_dt']);
                $w = $this->workup($b[$g], $otherCodes);
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
                    'days_with_impr'   => count($b[$g]),
                    'first_date'       => $b[$g][0]['_dt']->format('Y-m-d'),
                    'last_date'        => end($b[$g])['_dt']->format('Y-m-d'),
                    'events'           => implode(', ', array_map(fn($f) => (string)($f['redcap_event_name'] ?? ''), $b[$g])),
                    'cbc_done'         => $w['cbc'] ? 'Y' : 'N',
                    'crp_done'         => $w['crp'] ? 'Y' : 'N',
                    'culture_done'     => $w['culture'] ? 'Y' : 'N',
                    'culture_result'   => $w['culture'] ? self::RESULT_LABEL[$w['result']] : '',
                    'no_test_done'     => $noTest ? 'Y' : 'N',
                    'other_impression' => empty($b[$other]) ? '' : self::GROUPS[$other] . ' on ' . count($b[$other]) . ' day(s)',
                ];
            }

            // Form-level audit rows (every CONF / SUSP form in the period)
            foreach (['conf', 'susp'] as $g) {
                foreach ($b[$g] ?? [] as $f) {
                    $res = trim((string)($f['nss_blood_culture_result'] ?? ''));
                    $forms[] = [
                        'record_id'      => (string)$rid,
                        'site'           => $site,
                        'event'          => (string)($f['redcap_event_name'] ?? ''),
                        'nss_datetime'   => $f['_dt']->format('Y-m-d H:i'),
                        'impression'     => self::GROUPS[$g],
                        'cbc'            => RedcapFieldReader::isChecked($f[self::CBC_COLUMN] ?? '') ? 'Y' : 'N',
                        'crp_value'      => trim((string)($f['nss_crp'] ?? '')),
                        'blood_culture'  => trim((string)($f['nss_blood_culture'] ?? '')),
                        'culture_result' => $res === '' ? '' : RedcapFieldReader::label($this->dict, 'nss_blood_culture_result', $res),
                        'counted_in'     => in_array($g, $groups, true) ? self::GROUPS[$g] : 'Not counted (baby counted as confirmed)',
                    ];
                }
            }
        }

        $meta = self::metricMeta($this->exclusive);

        return [
            'by_site'            => self::finalise($bySite, $meta, $this->siteFilter),
            'metrics'            => $meta,
            'per_baby'           => $perBaby,
            'forms'              => $forms,
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

    /** Collapse one baby's forms (for one impression) into test flags + a single culture result. */
    private function workup(array $forms, array &$otherCodes): array
    {
        $w = ['cbc' => false, 'crp' => false, 'culture' => false, 'result' => 'cult_pending'];
        $rank = ['cult_pending' => 0, 'cult_other' => 1, 'cult_neg' => 2, 'cult_pos' => 3];

        foreach ($forms as $f) {
            if (RedcapFieldReader::isChecked($f[self::CBC_COLUMN] ?? '')) $w['cbc'] = true;

            $crp = trim((string)($f['nss_crp'] ?? ''));
            if ($crp !== '' && $crp !== self::CRP_MISSING) $w['crp'] = true;

            if (trim((string)($f['nss_blood_culture'] ?? '')) === 'Y') {
                $w['culture'] = true;
                $code = trim((string)($f['nss_blood_culture_result'] ?? ''));
                $res = match (true) {
                    $code === self::CULTURE_POS => 'cult_pos',
                    $code === self::CULTURE_NEG => 'cult_neg',
                    $code === ''                => 'cult_pending',
                    default                     => 'cult_other',
                };
                if ($res === 'cult_other') $otherCodes[$code] = ($otherCodes[$code] ?? 0) + 1;
                if ($rank[$res] > $rank[$w['result']]) $w['result'] = $res;
            }
        }
        return $w;
    }

    private function inc(array &$bySite, string $site, string $metric): void
    {
        $bySite[$site][$metric] = ($bySite[$site][$metric] ?? 0) + 1;
    }
}
