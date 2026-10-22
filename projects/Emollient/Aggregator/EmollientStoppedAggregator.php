<?php

namespace CEL\Projects\Emollient\Aggregator;

/**
 * EmollientStoppedAggregator
 *
 * Helper used by WeeklyMeetingAggregator — Section 5: Emolliation Stopped.
 *
 * Source : daily_interventionemolliation_form (repeating, up to 3 instances per study day,
 *          day0_arm_1 .. day28_arm_1)
 * Date   : int_datetime (instance must fall inside the report period)
 * Site   : int_hosp_code, falling back to the baby's enr_hosp_code
 *
 * An INSTANCE = one form row (record_id + event + repeat instance).
 *   Recorded : int_emoliate_baby is not blank
 *   Stopped  : int_emoliate_baby = 'N'
 *   Reason   : int_no_emol_reason             (master reason)
 *              int_doctor_deny_reason_list    (doctor-deny detail)
 *              int_sae_reasons                (SAE detail, checkbox)
 *              Free text (Excel only): int_doctor_deny_othr_reason, int_other_no_emoll_reason
 *
 * Reported three ways:
 *   baby-wise     — babies affected at all, and babies who lost a whole study day
 *   instance-wise — applications not given, by reason, with % of recorded
 *   day-wise      — study days (baby × event) with >= 1 instance stopped, and
 *                   study days fully stopped
 *
 * Fully stopped day (study day = redcap event):
 *   day1..day28 : at least FULL_DAY_ENTRIES (3) entries recorded and all are N.
 *                 A day with only 1-2 entries, all N, is NOT a full day (it may be
 *                 incomplete entry or discharge that day) — listed in Excel instead.
 *   day0        : all recorded entries are N (1-3 sessions are expected on Day 0,
 *                 depending on the time of enrolment).
 *
 * Codes are shown as stored in REDCap (raw). Pass $dict [field => [code => label]]
 * to show labels instead.
 *
 * Output (site-major; WeeklyMeetingAggregator converts to metric-major):
 *   by_site   [site][metric] + ['total']
 *   metrics   [metric] => [label, role, section, pct_of]   (reason rows are built from the data)
 *   per_baby  one row per baby with >= 1 stopped instance  → Excel "Emollient PerBaby"
 *   instances one row per stopped instance                 → Excel "Emollient Stopped"
 *   skipped   ['undated' => n, 'no_site' => n]
 */
final class EmollientStoppedAggregator
{
    public const GIVEN_FIELD  = 'int_emoliate_baby';
    public const MAIN_FIELD   = 'int_no_emol_reason';
    public const DOC_FIELD    = 'int_doctor_deny_reason_list';
    public const SAE_FIELD    = 'int_sae_reasons';
    public const DOC_TEXT     = 'int_doctor_deny_othr_reason';
    public const OTHER_TEXT   = 'int_other_no_emoll_reason';

    public const FULL_DAY_ENTRIES = 3;
    public const DAY0_EVENT       = 'day0_arm_1';

    public const KEEP = [
        'record_id', 'redcap_event_name', 'redcap_repeat_instance',
        'int_datetime', 'int_hosp_code', self::GIVEN_FIELD, self::MAIN_FIELD,
        self::DOC_FIELD, self::SAE_FIELD, self::DOC_TEXT, self::OTHER_TEXT,
    ];

    public function __construct(
        private ?\DateTimeInterface $from = null,
        private ?\DateTimeInterface $to = null,
        private array $siteFilter = [],
        private array $dict = [],
    ) {}

    public static function wants(array $row): bool
    {
        return trim((string)($row[self::GIVEN_FIELD] ?? '')) !== '';
    }

    public static function slim(array $row): array
    {
        $out = array_intersect_key($row, array_flip(self::KEEP));
        foreach ($row as $k => $v) {
            $k = (string)$k;
            if (str_starts_with($k, self::SAE_FIELD . '___')
             || str_starts_with($k, self::DOC_FIELD . '___')
             || str_starts_with($k, self::MAIN_FIELD . '___')) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    public function aggregate(iterable $rows, array $siteOfRecord = []): array
    {
        $skipped = ['undated' => 0, 'no_site' => 0];
        $babies  = [];
        $instances = [];
        $codes   = ['main' => [], 'doc' => [], 'sae' => []];
        $bySite  = [];

        foreach ($rows as $r) {
            $given = strtoupper(trim((string)($r[self::GIVEN_FIELD] ?? '')));
            if ($given === '') continue;

            $dt = RedcapFieldReader::date($r['int_datetime'] ?? '');
            if ($dt === null) { $skipped['undated']++; continue; }
            if (!RedcapFieldReader::inPeriod($dt, $this->from, $this->to)) continue;

            $rid  = (string)$r['record_id'];
            $site = trim((string)($r['int_hosp_code'] ?? '')) ?: (string)($siteOfRecord[$rid] ?? '');
            if ($site === '') { $skipped['no_site']++; continue; }
            if ($this->siteFilter && !in_array($site, $this->siteFilter, true)) continue;

            $event = (string)($r['redcap_event_name'] ?? '');
            $day   = $event !== '' ? $event : $dt->format('Y-m-d');   // study day = event

            $b = &$babies[$rid];
            $b['site'] ??= $site;
            $b['recorded'] = ($b['recorded'] ?? 0) + 1;
            $b['days'][$day]['rec'] = ($b['days'][$day]['rec'] ?? 0) + 1;
            $this->inc($bySite, $site, 'emo_inst_recorded');

            if ($given === 'N') {
                $main = RedcapFieldReader::codes($r, self::MAIN_FIELD, $this->dict[self::MAIN_FIELD] ?? []);
                $doc  = RedcapFieldReader::codes($r, self::DOC_FIELD,  $this->dict[self::DOC_FIELD]  ?? []);
                $sae  = RedcapFieldReader::codes($r, self::SAE_FIELD,  $this->dict[self::SAE_FIELD]  ?? []);

                $b['stopped'] = ($b['stopped'] ?? 0) + 1;
                $b['days'][$day]['stop'] = ($b['days'][$day]['stop'] ?? 0) + 1;
                $b['dates'][] = $dt;
                $this->inc($bySite, $site, 'emo_inst_stopped');

                if ($main) {
                    foreach ($main as $c) {
                        $this->inc($bySite, $site, "emo_main:$c");
                        $codes['main'][$c] = true;
                        $b['main'][$c] = ($b['main'][$c] ?? 0) + 1;
                    }
                } else {
                    $this->inc($bySite, $site, 'emo_main_none');
                    $b['none'] = ($b['none'] ?? 0) + 1;
                }
                foreach ($doc as $c) { $this->inc($bySite, $site, "emo_doc:$c"); $codes['doc'][$c] = true; $b['doc'][$c] = ($b['doc'][$c] ?? 0) + 1; }
                foreach ($sae as $c) { $this->inc($bySite, $site, "emo_sae:$c"); $codes['sae'][$c] = true; $b['sae'][$c] = ($b['sae'][$c] ?? 0) + 1; }

                $instances[] = [
                    'record_id'         => $rid,
                    'site'              => $site,
                    'event'             => $event,
                    'instance'          => (string)($r['redcap_repeat_instance'] ?? ''),
                    'int_datetime'      => $dt->format('Y-m-d H:i'),
                    'reason'            => $this->labels(self::MAIN_FIELD, $main),
                    'doctor_deny'       => $this->labels(self::DOC_FIELD, $doc),
                    'doctor_deny_other' => trim((string)($r[self::DOC_TEXT] ?? '')),
                    'sae'               => $this->labels(self::SAE_FIELD, $sae),
                    'other_reason_text' => trim((string)($r[self::OTHER_TEXT] ?? '')),
                ];
            }
            unset($b);
        }
        uksort($babies, 'strnatcmp');
        usort($instances, fn($x, $y) => strnatcmp($x['record_id'], $y['record_id']) ?: strcmp($x['int_datetime'], $y['int_datetime']));

        // ── Baby-wise counts + per-baby detail ───────────────────────────────
        $perBaby = [];
        foreach ($babies as $rid => $b) {
            $site = $b['site'];
            $this->inc($bySite, $site, 'emo_babies_recorded');
            if (empty($b['stopped'])) continue;

            $daysWithStop = 0; $fullDays = 0; $incompleteAllN = 0;
            foreach ($b['days'] as $dayKey => $d) {
                if (empty($d['stop'])) continue;
                $daysWithStop++;
                $this->inc($bySite, $site, 'emo_days_stopped');
                if ($this->isFullDay((string)$dayKey, $d['rec'], $d['stop'])) {
                    $fullDays++;
                    $this->inc($bySite, $site, 'emo_days_full');
                } elseif ($d['stop'] === $d['rec']) {
                    $incompleteAllN++;   // all N, but fewer than 3 entries
                }
            }
            $this->inc($bySite, $site, 'emo_babies_stopped');
            if ($fullDays > 0)     $this->inc($bySite, $site, 'emo_babies_fullday');
            if (!empty($b['doc'])) $this->inc($bySite, $site, 'emo_babies_doc');
            if (!empty($b['sae'])) $this->inc($bySite, $site, 'emo_babies_sae');

            $perBaby[] = [
                'record_id'          => (string)$rid,
                'site'               => $site,
                'instances_recorded' => $b['recorded'],
                'instances_stopped'  => $b['stopped'],
                'pct_stopped'        => round(100 * $b['stopped'] / $b['recorded'], 1),
                'days_with_stop'     => $daysWithStop,
                'full_days_stopped'  => $fullDays,
                'days_all_n_lt3_entries' => $incompleteAllN,
                'first_stop'         => min($b['dates'])->format('Y-m-d'),
                'last_stop'          => max($b['dates'])->format('Y-m-d'),
                'reasons'            => $this->countedLabels(self::MAIN_FIELD, $b['main'] ?? []),
                'doctor_deny'        => $this->countedLabels(self::DOC_FIELD, $b['doc'] ?? []),
                'sae'                => $this->countedLabels(self::SAE_FIELD, $b['sae'] ?? []),
                'stops_no_reason'    => $b['none'] ?? 0,
            ];
        }

        $meta = $this->metricMeta(array_keys($codes['main']), array_keys($codes['doc']), array_keys($codes['sae']));

        return [
            'by_site'   => SepsisWorkupAggregator::finalise($bySite, $meta, $this->siteFilter),
            'metrics'   => $meta,
            'per_baby'  => $perBaby,
            'instances' => $instances,
            'skipped'   => $skipped,
        ];
    }

    // =========================================================================

    private function metricMeta(array $mainCodes, array $docCodes, array $saeCodes): array
    {
        $order = function (string $field, array $codes): array {
            $known = array_values(array_intersect(array_keys($this->dict[$field] ?? []), $codes));
            $rest  = array_values(array_diff($codes, $known));
            sort($rest);
            return array_merge($known, $rest);
        };

        $sb = 'Baby-wise';
        $si = 'Instance-wise (one instance = one emolliation entry, up to 3 per day)';
        $sy = 'Day-wise (one study day = one baby on one study day, up to 3 entries)';
        $sm = 'Reason not emolliated (int_no_emol_reason)';
        $sd = 'Doctor denied — detail (int_doctor_deny_reason_list)';
        $ss = 'SAE — detail (int_sae_reasons)';

        $m = [
            'emo_babies_recorded' => ['label' => 'Babies with emolliation recorded', 'role' => 'primary', 'section' => $sb, 'pct_of' => null],
            'emo_babies_stopped'  => ['label' => 'Babies with ≥1 instance stopped',   'role' => 'sub',     'section' => $sb, 'pct_of' => 'emo_babies_recorded'],
            'emo_babies_fullday'  => ['label' => 'Babies with ≥1 full day stopped',   'role' => 'sub',     'section' => $sb, 'pct_of' => 'emo_babies_recorded'],
            'emo_babies_doc'      => ['label' => 'Babies with a doctor-deny reason',  'role' => 'sub',     'section' => $sb, 'pct_of' => 'emo_babies_stopped'],
            'emo_babies_sae'      => ['label' => 'Babies with an SAE reason',         'role' => 'sub',     'section' => $sb, 'pct_of' => 'emo_babies_stopped'],
            'emo_inst_recorded'   => ['label' => 'Emolliation instances recorded',    'role' => 'primary', 'section' => $si, 'pct_of' => null],
            'emo_inst_stopped'    => ['label' => 'Instances not emolliated (stopped)', 'role' => 'sub',    'section' => $si, 'pct_of' => 'emo_inst_recorded'],
            'emo_days_stopped'    => ['label' => 'Study days with ≥1 instance stopped', 'role' => 'primary', 'section' => $sy, 'pct_of' => null],
            'emo_days_full'       => ['label' => 'Study days fully stopped (all 3 entries N)', 'role' => 'sub', 'section' => $sy, 'pct_of' => 'emo_days_stopped'],
        ];
        foreach ($order(self::MAIN_FIELD, $mainCodes) as $c) {
            $m["emo_main:$c"] = ['label' => RedcapFieldReader::label($this->dict, self::MAIN_FIELD, $c), 'role' => 'sub', 'section' => $sm, 'pct_of' => 'emo_inst_stopped'];
        }
        $m['emo_main_none'] = ['label' => 'Reason not recorded', 'role' => 'sub', 'section' => $sm, 'pct_of' => 'emo_inst_stopped'];
        foreach ($order(self::DOC_FIELD, $docCodes) as $c) {
            $m["emo_doc:$c"] = ['label' => RedcapFieldReader::label($this->dict, self::DOC_FIELD, $c), 'role' => 'sub2', 'section' => $sd, 'pct_of' => 'emo_inst_stopped'];
        }
        foreach ($order(self::SAE_FIELD, $saeCodes) as $c) {
            $m["emo_sae:$c"] = ['label' => RedcapFieldReader::label($this->dict, self::SAE_FIELD, $c), 'role' => 'sub2', 'section' => $ss, 'pct_of' => 'emo_inst_stopped'];
        }
        return $m;
    }

    /** See class docblock: 3 entries all N on day1..day28; all recorded N on day0. */
    private function isFullDay(string $dayKey, int $rec, int $stop): bool
    {
        if ($stop !== $rec) return false;
        if ($dayKey === self::DAY0_EVENT) return true;
        return $rec >= self::FULL_DAY_ENTRIES;
    }

    private function labels(string $field, array $codes): string
    {
        return implode('; ', array_map(fn($c) => RedcapFieldReader::label($this->dict, $field, $c), $codes));
    }

    private function countedLabels(string $field, array $counts): string
    {
        $out = [];
        foreach ($counts as $c => $n) $out[] = RedcapFieldReader::label($this->dict, $field, (string)$c) . " ×$n";
        return implode('; ', $out);
    }

    private function inc(array &$bySite, string $site, string $metric): void
    {
        $bySite[$site][$metric] = ($bySite[$site][$metric] ?? 0) + 1;
    }
}
