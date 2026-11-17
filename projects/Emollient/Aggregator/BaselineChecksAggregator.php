<?php

namespace CEL\Projects\Emollient\Aggregator;

/**
 * BaselineChecksAggregator
 *
 * Helper used by WeeklyMeetingAggregator — two sections from baseline_arm_1
 * (one row per baby; both study arms together). Unit of count = baby.
 * Site = the baby's enr_hosp_code (the baseline forms carry no site field).
 *
 * ── Section 6: Antenatal Corticosteroids (maternal_history_module) ───────────
 *   In scope   : mat_start_time falls in the period
 *   Marked     : mat_antenatal_steriod = 'Y'
 *   Not marked : any other value, or blank
 *
 * ── Section 7: Gestational Age Sources (baseline_information_module) ─────────
 *   In scope   : base_datetime falls in the period
 *   Sources    : base_ga_source_avail (checkbox: LMP, USG, BALLARD, NONE)
 *   Trimester  : base_usg_trimester (1, 2, 3)
 *   USG marked → 1st / 2nd / 3rd trimester / trimester not marked
 *   USG not marked → LMP and/or Ballard only / None / nothing marked
 *   No 1st-trimester USG = every baby in scope except "USG marked, 1st trimester"
 *                          (the list sites follow up)
 *
 * Output (site-major; WeeklyMeetingAggregator converts to metric-major):
 *   ans_by_site / ans_metrics / ans_rows     → Section 6 + Excel "Antenatal Steroid"
 *   ga_by_site  / ga_metrics  / ga_rows      → Section 7 + Excel "GA Source"
 *   skipped ['ans_undated' => n, 'ga_undated' => n, 'no_site' => n]
 */
final class BaselineChecksAggregator
{
    public const EVENT = 'baseline_arm_1';

    public const KEEP = [
        'record_id', 'redcap_event_name',
        'mat_start_time', 'mat_antenatal_steriod',
        'base_datetime', 'base_usg_trimester',
    ];

    public function __construct(
        private ?\DateTimeInterface $from = null,
        private ?\DateTimeInterface $to = null,
        private array $siteFilter = [],
    ) {}

    public static function wants(array $row): bool
    {
        return ($row['redcap_event_name'] ?? self::EVENT) === self::EVENT
            && (trim((string)($row['mat_start_time'] ?? '')) !== ''
             || trim((string)($row['base_datetime'] ?? '')) !== ''
             || trim((string)($row['mat_antenatal_steriod'] ?? '')) !== ''
             || trim((string)($row['base_usg_trimester'] ?? '')) !== ''
             || self::gaSources($row) !== []);
    }

    public static function slim(array $row): array
    {
        $out = array_intersect_key($row, array_flip(self::KEEP));
        foreach ($row as $k => $v) {
            if (str_starts_with((string)$k, 'base_ga_source_avail___')) $out[$k] = $v;
        }
        return $out;
    }

    public static function antenatalMeta(): array
    {
        $s = 'Maternal History forms this period (mat_start_time)';
        return [
            'ans_forms'      => ['label' => 'Babies with Maternal History filled this period', 'role' => 'primary', 'section' => $s, 'pct_of' => null],
            'ans_marked'     => ['label' => 'Antenatal steroid marked (Y)',                    'role' => 'sub',     'section' => $s, 'pct_of' => 'ans_forms'],
            'ans_not_marked' => ['label' => 'Not marked (any other value or blank)',          'role' => 'sub',     'section' => $s, 'pct_of' => 'ans_forms'],
        ];
    }

    public static function gaMeta(): array
    {
        $s = 'Baseline General Information forms this period (base_datetime)';
        $m = fn($label, $role, $pct) => ['label' => $label, 'role' => $role, 'section' => $s, 'pct_of' => $pct];
        return [
            'ga_forms'        => $m('Babies with Baseline General Information filled this period', 'primary', null),
            'ga_usg'          => $m('USG marked as a GA source',              'sub',  'ga_forms'),
            'ga_usg_t1'       => $m('1st trimester',                          'sub2', 'ga_usg'),
            'ga_usg_t2'       => $m('2nd trimester',                          'sub2', 'ga_usg'),
            'ga_usg_t3'       => $m('3rd trimester',                          'sub2', 'ga_usg'),
            'ga_usg_tnone'    => $m('Trimester not marked',                   'sub2', 'ga_usg'),
            'ga_no_usg'       => $m('USG not marked',                         'sub',  'ga_forms'),
            'ga_lmp_ballard'  => $m('LMP and/or Ballard only',                'sub2', 'ga_no_usg'),
            'ga_none'         => $m('None',                                   'sub2', 'ga_no_usg'),
            'ga_nothing'      => $m('No source marked',                       'sub2', 'ga_no_usg'),
            'ga_no_t1_usg'    => $m('No 1st-trimester USG (list in Excel “GA Source”)', 'primary', 'ga_forms'),
        ];
    }

    public function aggregate(iterable $rows, array $siteOfRecord = []): array
    {
        $skipped = ['ans_undated' => 0, 'ga_undated' => 0, 'no_site' => 0];
        $ans = []; $ga = []; $ansRows = []; $gaRows = [];

        foreach ($rows as $r) {
            if (!self::wants($r)) continue;
            $rid  = (string)$r['record_id'];
            $site = (string)($siteOfRecord[$rid] ?? '');

            // ── Section 6: antenatal steroid ─────────────────────────────────
            $matDt = RedcapFieldReader::date($r['mat_start_time'] ?? '');
            if ($matDt === null) {
                if (trim((string)($r['mat_antenatal_steriod'] ?? '')) !== '') $skipped['ans_undated']++;
            } elseif (RedcapFieldReader::inPeriod($matDt, $this->from, $this->to)) {
                if ($site === '') { $skipped['no_site']++; }
                elseif (!$this->siteFilter || in_array($site, $this->siteFilter, true)) {
                    $val    = trim((string)($r['mat_antenatal_steriod'] ?? ''));
                    $marked = strtoupper($val) === 'Y';
                    $this->inc($ans, $site, 'ans_forms');
                    $this->inc($ans, $site, $marked ? 'ans_marked' : 'ans_not_marked');
                    $ansRows[] = [
                        'record_id'             => $rid,
                        'site'                  => $site,
                        'mat_start_time'          => $matDt->format('Y-m-d H:i'),
                        'mat_antenatal_steriod' => $val,
                        'status'                => $marked ? 'Marked' : 'Not marked',
                    ];
                }
            }

            // ── Section 7: GA sources ────────────────────────────────────────
            $baseDt  = RedcapFieldReader::date($r['base_datetime'] ?? '');
            $sources = self::gaSources($r);
            $tri     = trim((string)($r['base_usg_trimester'] ?? ''));
            if ($baseDt === null) {
                if ($sources || $tri !== '') $skipped['ga_undated']++;
                continue;
            }
            if (!RedcapFieldReader::inPeriod($baseDt, $this->from, $this->to)) continue;
            if ($site === '') { $skipped['no_site']++; continue; }
            if ($this->siteFilter && !in_array($site, $this->siteFilter, true)) continue;

            $this->inc($ga, $site, 'ga_forms');
            $hasUsg = in_array('USG', $sources, true);
            if ($hasUsg) {
                $this->inc($ga, $site, 'ga_usg');
                $key = match ($tri) { '1' => 'ga_usg_t1', '2' => 'ga_usg_t2', '3' => 'ga_usg_t3', default => 'ga_usg_tnone' };
                $this->inc($ga, $site, $key);
                $reason = match ($tri) { '1' => null, '2' => 'USG 2nd trimester', '3' => 'USG 3rd trimester', '' => 'USG trimester not marked', default => "USG trimester '{$tri}'" };
            } else {
                $this->inc($ga, $site, 'ga_no_usg');
                $hasLmpBallard = in_array('LMP', $sources, true) || in_array('BALLARD', $sources, true);
                $this->inc($ga, $site, match (true) {
                    $hasLmpBallard              => 'ga_lmp_ballard',
                    in_array('NONE', $sources, true) => 'ga_none',
                    default                     => 'ga_nothing',
                });
                $reason = $sources ? 'No USG' : 'No source marked';
            }

            if ($reason !== null) {
                $this->inc($ga, $site, 'ga_no_t1_usg');
                $gaRows[] = [
                    'record_id'         => $rid,
                    'site'              => $site,
                    'base_datetime'     => $baseDt->format('Y-m-d H:i'),
                    'ga_sources_marked' => $sources ? implode(' + ', array_map([self::class, 'sourceLabel'], $sources)) : '(none marked)',
                    'usg_trimester'     => $tri,
                    'reason'            => $reason,
                ];
            }
        }

        $byId = fn($a, $b) => strnatcmp($a['site'], $b['site']) ?: strnatcmp($a['record_id'], $b['record_id']);
        usort($ansRows, $byId);
        usort($gaRows, $byId);

        return [
            'ans_by_site' => SepsisWorkupAggregator::finalise($ans, self::antenatalMeta(), $this->siteFilter),
            'ans_metrics' => self::antenatalMeta(),
            'ans_rows'    => $ansRows,
            'ga_by_site'  => SepsisWorkupAggregator::finalise($ga, self::gaMeta(), $this->siteFilter),
            'ga_metrics'  => self::gaMeta(),
            'ga_rows'     => $gaRows,
            'skipped'     => $skipped,
        ];
    }

    // =========================================================================

    /** Ticked GA source codes, upper-case, in a fixed order (LMP, USG, BALLARD, NONE, others). */
    public static function gaSources(array $row): array
    {
        $codes = array_map('strtoupper', RedcapFieldReader::codes($row, 'base_ga_source_avail'));
        $order = ['LMP' => 0, 'USG' => 1, 'BALLARD' => 2, 'NONE' => 3];
        usort($codes, fn($a, $b) => ($order[$a] ?? 9) <=> ($order[$b] ?? 9) ?: strcmp($a, $b));
        return array_values(array_unique($codes));
    }

    public static function sourceLabel(string $code): string
    {
        return match ($code) { 'LMP' => 'LMP', 'USG' => 'Ultrasound', 'BALLARD' => 'Ballard score', 'NONE' => 'None', default => $code };
    }

    private function inc(array &$bySite, string $site, string $metric): void
    {
        $bySite[$site][$metric] = ($bySite[$site][$metric] ?? 0) + 1;
    }
}
