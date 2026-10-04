<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AbstractAggregator;

/**
 * MonthlySiteSummaryAggregator
 *
 * Month × site counts of Enrollment, LAMA, DOPR and SAE for the TSC report.
 *
 * ── Definitions (as specified 4 Oct 2026) ────────────────────────────────
 *
 *   Enrolled   enr_consent_granted in ('1','Y')  AND  enr_datetime present
 *              → counted in the month of enr_datetime
 *
 *   LAMA       dis_discharge_type = 'TYP_LAMA'
 *   DOPR       dis_discharge_type = 'TYP_DOPR'
 *              → counted in the month of dis_datetime
 *
 *   SAE        sae_start_date present   (Q8, "the date when the adverse
 *              event started" — NOT Q5 sae_datetime)
 *              → counted in the month of sae_start_date
 *
 * THE COUNTING RULE, stated once because everything below follows from it:
 * an event is counted in the month of its OWN date, and only when that date
 * is present. An event recorded without its date is not counted at all; it is
 * tallied in diagnostics instead, so the gap is visible rather than silent.
 * This is applied to BOTH bases below, so both have the same denominator and
 * the same grand total — they differ only in how the same events are spread
 * across months.
 *
 * ── Two bases, side by side ──────────────────────────────────────────────
 *
 *   'event'   An event lands in the month it happened. Answers "what
 *             happened last quarter". The usual monitoring view.
 *
 *   'cohort'  An event lands in the month the baby was ENROLLED. Lets a count
 *             be read against that month's enrollment denominator, so a rate
 *             means something. Recent months keep moving as outcomes arrive.
 *
 * THE TWO BASES DO NOT ALWAYS AGREE, and the difference is not a rounding
 * artefact. The cohort basis can only place an event if the baby has a usable
 * enrollment month; a baby whose consent was refused, or whose enr_datetime is
 * missing or falls outside the reporting window, can still have a dated
 * discharge or SAE. Such an event is counted on the event basis and is
 * unplaceable on the cohort basis. Every one of these is counted in
 * diagnostics['cohort_unplaceable'] per measure, so the gap between the two
 * grand totals is always exactly accounted for — never present the two bases
 * as alternative renderings of one number.
 *
 * ── Deliberate exclusions, stated on the report ──────────────────────────
 *
 *   - discharge_after_28_days_of_stay (dis_post_28_discharge_type) is NOT
 *     read. Babies still in hospital at day 28 are discharged through that
 *     form, so their LAMA/DOPR are not counted here. Excluded on instruction
 *     of 4 Oct 2026; set $includePost28 = true to include them.
 *   - discharge_of_babies_denied_by_physician is not read.
 *
 * ── Why LAMA and DOPR are not merged ─────────────────────────────────────
 *
 * Four incompatible definitions of "LAMA" exist across this codebase (see
 * Documentation/Emollient_Measure_Definitions.xlsx). This aggregator commits
 * to none of them: it counts every discharge type separately and lets the
 * exporter sum whichever subset the audience asked for. Only LAMA, DOPR and
 * their total are displayed; the rest ride along in 'discharge_types' for the
 * CSV, so a question about referrals does not require a code change.
 *
 * Required fields (reports.php):
 *   record_id, enr_datetime, enr_consent_granted, enr_hosp_code,
 *   enr_study_arm, dis_datetime, dis_discharge_type, dis_in_hosp,
 *   dis_hosp_code, sae_start_date, sae_datetime, sae_hosp_code
 *
 * Required events:
 *   day0_arm_1, discharge_arm_1, other_forms_arm_1
 */
class MonthlySiteSummaryAggregator extends AbstractAggregator
{
    /** Every coded value of dis_discharge_type, in data-dictionary order. */
    public const DISCHARGE_TYPES = [
        'TYP_FP'   => 'Discharge as per facility protocol',
        'TYP_LAMA' => 'LAMA',
        'TYP_DOPR' => 'Discharge on patient request',
        'TYP_REF'  => 'Referred out',
        'TYP_DEA'  => 'Death',
        'TYP_ABS'  => 'Absconded',
        'TYP_OTH'  => 'Others',
    ];

    /** The measures shown on the report, in display order. */
    public const MEASURES = [
        'enrolled'  => 'Enrolled',
        'lama'      => 'LAMA',
        'dopr'      => 'DOPR',
        'unplanned' => 'LAMA + DOPR',
        'sae'       => 'SAE',
    ];

    private string  $primaryKey;
    private array   $siteFilter;
    private ?\DateTime $from;
    private ?\DateTime $to;
    private array   $siteLabels;
    private bool    $includePost28;

    /** Counters for things that did not make it into the numbers. */
    private array $diag = [];

    public function __construct(
        string  $primaryKey,
        array   $siteFilter    = [],
        ?string $dateFrom      = null,
        ?string $dateTo        = null,
        array   $siteLabels    = [],
        bool    $includePost28 = false
    ) {
        $this->primaryKey    = $primaryKey;
        $this->siteFilter    = array_filter(array_map('trim', $siteFilter));
        $this->from          = $dateFrom ? (new \DateTime($dateFrom))->setTime(0, 0, 0)   : null;
        $this->to            = $dateTo   ? (new \DateTime($dateTo))->setTime(23, 59, 59)  : null;
        $this->siteLabels    = $siteLabels;
        $this->includePost28 = $includePost28;
    }

    // =====================================================================
    // Main
    // =====================================================================

    public function aggregate(iterable $records): array
    {
        $this->diag = [
            'rows_seen'              => 0,
            'babies_seen'            => 0,
            // no_site is the sum of the next two. It is kept as a total
            // because the two populations are completely different and
            // reporting only the sum is alarming for no reason: most records
            // in a REDCap export are prescreening instances that were never
            // enrolled and correctly have no site.
            'no_site'                => 0,
            'no_site_not_enrolled'   => 0,   // expected — never enrolled
            'no_site_enrolled'       => 0,   // a real data problem
            'filtered_out_by_site'   => 0,
            'enrolled_no_date'       => 0,
            'enrolled_bad_date'      => 0,
            'discharge_no_date'      => [],   // type => n
            'sae_form_present'       => 0,
            'sae_q8_missing'         => 0,    // SAE present, Q8 blank → NOT counted
            'sae_q8_only'            => 0,
            'sae_q5_only'            => 0,
            'sae_both'               => 0,
            'sae_q8_bad_date'        => 0,
            'site_mismatch_discharge'=> 0,
            'site_mismatch_sae'      => 0,
            'outside_period'         => 0,
            // Events counted on the event basis that the cohort basis cannot
            // place, because the baby has no usable enrollment month. This is
            // exactly the gap between event_grand and cohort_grand.
            'cohort_unplaceable'     => array_fill_keys(array_keys(self::MEASURES), 0),
        ];

        // ── Step 1: group the flat export by record ──────────────────────
        $byId = [];
        foreach ($records as $row) {
            $this->diag['rows_seen']++;
            $id = $row[$this->primaryKey] ?? null;
            if ($id === null || $id === '') continue;
            $byId[$id][] = $row;
        }
        $this->diag['babies_seen'] = count($byId);

        // ── Step 2: reduce each baby to a small set of facts ─────────────
        $babies = [];
        foreach ($byId as $id => $rows) {
            $b = $this->summariseBaby($id, $rows);
            if ($b !== null) $babies[] = $b;
        }

        // ── Step 3: decide the month axis ────────────────────────────────
        $months = $this->buildMonthAxis($babies);

        // ── Step 4: decide the site axis ─────────────────────────────────
        $sites = $this->buildSiteAxis($babies);

        // ── Step 5: fill both grids ──────────────────────────────────────
        $event  = $this->emptyGrid($sites, $months);
        $cohort = $this->emptyGrid($sites, $months);
        $types  = $this->emptyTypeGrid($sites, $months);

        foreach ($babies as $b) {
            $site       = $b['site'];
            $enrMonth   = $b['enr_month'];           // null if not enrolled/no date
            if (!isset($event[$site])) continue;     // filtered out

            // -- Enrolment ------------------------------------------------
            if ($enrMonth !== null && isset($event[$site][$enrMonth])) {
                $event[$site][$enrMonth]['enrolled']++;
                $cohort[$site][$enrMonth]['enrolled']++;
            }

            // -- Discharge types ------------------------------------------
            // Counted in the month of dis_datetime (event basis) or the month
            // of enrolment (cohort basis). Both require dis_datetime to be
            // present, per the counting rule above.
            $disMonth = $b['dis_month'];
            $disType  = $b['dis_type'];

            if ($disType !== null && $disMonth !== null) {
                if (isset($types[$site][$disMonth])) {
                    $types[$site][$disMonth][$disType]++;
                }
                $key = match ($disType) {
                    'TYP_LAMA' => 'lama',
                    'TYP_DOPR' => 'dopr',
                    default    => null,
                };
                if ($key !== null) {
                    if (isset($event[$site][$disMonth])) {
                        $event[$site][$disMonth][$key]++;
                        $event[$site][$disMonth]['unplanned']++;
                    }
                    if ($enrMonth !== null && isset($cohort[$site][$enrMonth])) {
                        $cohort[$site][$enrMonth][$key]++;
                        $cohort[$site][$enrMonth]['unplanned']++;
                    } else {
                        $this->diag['cohort_unplaceable'][$key]++;
                        $this->diag['cohort_unplaceable']['unplanned']++;
                    }
                }
            }

            // -- SAE ------------------------------------------------------
            $saeMonth = $b['sae_month'];
            if ($saeMonth !== null) {
                if (isset($event[$site][$saeMonth])) {
                    $event[$site][$saeMonth]['sae']++;
                }
                if ($enrMonth !== null && isset($cohort[$site][$enrMonth])) {
                    $cohort[$site][$enrMonth]['sae']++;
                } else {
                    $this->diag['cohort_unplaceable']['sae']++;
                }
            }
        }

        // ── Step 6: margins ──────────────────────────────────────────────
        return [
            'months'        => $months,
            'month_labels'  => array_map(fn($m) => $this->monthLabel($m), $months),
            'sites'         => $sites,
            'site_labels'   => $this->siteLabels,
            'measures'      => self::MEASURES,
            'discharge_type_labels' => self::DISCHARGE_TYPES,

            'event'         => $event,
            'cohort'        => $cohort,
            'discharge_types' => $types,

            'event_month_totals'  => $this->monthTotals($event,  $months),
            'cohort_month_totals' => $this->monthTotals($cohort, $months),
            'event_site_totals'   => $this->siteTotals($event),
            'cohort_site_totals'  => $this->siteTotals($cohort),
            'event_grand'         => $this->grandTotal($event),
            'cohort_grand'        => $this->grandTotal($cohort),

            'period' => [
                'date_from' => $this->from?->format('Y-m-d'),
                'date_to'   => $this->to?->format('Y-m-d'),
                'generated' => date('Y-m-d H:i'),
            ],
            'definitions' => $this->definitionNotes(),
            'exclusions'  => $this->exclusionNotes(),
            'diagnostics' => $this->diag,
        ];
    }

    // =====================================================================
    // One baby
    // =====================================================================

    /**
     * Reduce a baby's rows to the handful of facts the grids need.
     * Returns null when the baby has no site and so cannot be placed.
     */
    private function summariseBaby(string $id, array $rows): ?array
    {
        $site     = null;  $disSite = null;  $saeSite = null;
        $consent  = null;
        $enrRaw   = null;  $disRaw  = null;
        $disType  = null;  $inHosp  = null;
        $arm      = null;
        $saeQ8    = null;  $saeQ5   = null;
        $saeForm  = false;

        foreach ($rows as $row) {
            $event = $row['redcap_event_name'] ?? '';

            if ($event === 'day0_arm_1') {
                if (!empty($row['enr_hosp_code']))       $site    = trim($row['enr_hosp_code']);
                if (isset($row['enr_consent_granted']) && $row['enr_consent_granted'] !== '')
                                                         $consent = trim($row['enr_consent_granted']);
                if (!empty($row['enr_datetime']))        $enrRaw  = trim($row['enr_datetime']);
                if (!empty($row['enr_study_arm']))       $arm     = trim($row['enr_study_arm']);
            }

            if ($event === 'discharge_arm_1') {
                if (!empty($row['dis_hosp_code']))       $disSite = trim($row['dis_hosp_code']);
                if (!empty($row['dis_datetime']))        $disRaw  = trim($row['dis_datetime']);
                if (!empty($row['dis_in_hosp']))         $inHosp  = trim($row['dis_in_hosp']);
                if (!empty($row['dis_discharge_type']))  $disType = trim($row['dis_discharge_type']);

                // Long-stay discharges live on a second form with the same
                // code list. Off by default — see the class docblock.
                if ($this->includePost28) {
                    if ($disType === null && !empty($row['dis_post_28_discharge_type'])) {
                        $disType = trim($row['dis_post_28_discharge_type']);
                    }
                    if ($disRaw === null && !empty($row['dis_post_28_datetime'])) {
                        $disRaw = trim($row['dis_post_28_datetime']);
                    }
                }
            }

            if ($event === 'other_forms_arm_1') {
                if (!empty($row['sae_hosp_code']))       $saeSite = trim($row['sae_hosp_code']);
                if (!empty($row['sae_start_date']))      $saeQ8   = trim($row['sae_start_date']);
                if (!empty($row['sae_datetime']))        $saeQ5   = trim($row['sae_datetime']);
                if ($saeQ8 !== null || $saeQ5 !== null)  $saeForm = true;
            }
        }

        // ── Site ─────────────────────────────────────────────────────────
        // enr_hosp_code is authoritative: it is the field every other report
        // in this project uses, so using anything else here would make this
        // report disagree with them about which site a baby belongs to.
        if ($site === null || $site === '') {
            $this->diag['no_site']++;
            // A record with no site and no sign of enrolment is a prescreening
            // instance that never became a baby in this study — expected, and
            // the bulk of any export. A record that WAS enrolled but carries
            // no site is a genuine gap, and the only one worth chasing.
            if (in_array($consent, ['1', 'Y'], true) || $enrRaw !== null) {
                $this->diag['no_site_enrolled']++;
            } else {
                $this->diag['no_site_not_enrolled']++;
            }
            return null;
        }
        if ($disSite !== null && $disSite !== $site) $this->diag['site_mismatch_discharge']++;
        if ($saeSite !== null && $saeSite !== $site) $this->diag['site_mismatch_sae']++;

        if ($this->siteFilter && !in_array($site, $this->siteFilter, true)) {
            $this->diag['filtered_out_by_site']++;
            return null;
        }

        // ── Enrolment ────────────────────────────────────────────────────
        $enrolled = in_array($consent, ['1', 'Y'], true);
        $enrMonth = null;
        if ($enrolled) {
            if ($enrRaw === null) {
                $this->diag['enrolled_no_date']++;
            } else {
                $d = $this->parseDate($enrRaw);
                if ($d === null)                  $this->diag['enrolled_bad_date']++;
                elseif (!$this->inPeriod($d))     $this->diag['outside_period']++;
                else                              $enrMonth = $d->format('Y-m');
            }
        }

        // ── Discharge ────────────────────────────────────────────────────
        $disMonth = null;
        if ($disType !== null) {
            if ($disRaw === null) {
                // dis_datetime branches on dis_in_hosp = 'N'; a baby still in
                // hospital legitimately has a type but no date yet.
                $this->diag['discharge_no_date'][$disType] =
                    ($this->diag['discharge_no_date'][$disType] ?? 0) + 1;
            } else {
                $d = $this->parseDate($disRaw);
                if ($d !== null && $this->inPeriod($d)) $disMonth = $d->format('Y-m');
            }
        }

        // ── SAE ──────────────────────────────────────────────────────────
        // Q8 (sae_start_date) is the date, per instruction of 4 Oct 2026.
        // Q5 (sae_datetime) is recorded only to report how the two compare —
        // it never places an SAE in a month.
        $saeMonth = null;
        if ($saeForm) {
            $this->diag['sae_form_present']++;
            if     ($saeQ8 !== null && $saeQ5 !== null) $this->diag['sae_both']++;
            elseif ($saeQ8 !== null)                    $this->diag['sae_q8_only']++;
            elseif ($saeQ5 !== null)                    $this->diag['sae_q5_only']++;

            if ($saeQ8 === null) {
                $this->diag['sae_q8_missing']++;       // present but uncounted
            } else {
                $d = $this->parseDate($saeQ8);
                if ($d === null)                  $this->diag['sae_q8_bad_date']++;
                elseif ($this->inPeriod($d))      $saeMonth = $d->format('Y-m');
            }
        }

        return [
            'id'        => $id,
            'site'      => $site,
            'arm'       => $arm,
            'enr_month' => $enrMonth,
            'dis_month' => $disMonth,
            'dis_type'  => $disType,
            'sae_month' => $saeMonth,
            'in_hosp'   => $inHosp,
        ];
    }

    // =====================================================================
    // Axes
    // =====================================================================

    /**
     * Every month from the earliest event to the end of the period, with no
     * gaps — a missing month must render as a zero, not vanish, or a chart
     * will join March to May and imply a trend that is not there.
     */
    private function buildMonthAxis(array $babies): array
    {
        $seen = [];
        foreach ($babies as $b) {
            foreach (['enr_month', 'dis_month', 'sae_month'] as $k) {
                if ($b[$k] !== null) $seen[$b[$k]] = true;
            }
        }
        if (!$seen) return [];

        $keys  = array_keys($seen);
        sort($keys);
        $start = new \DateTime($keys[0] . '-01');
        $end   = $this->to
            ? (new \DateTime($this->to->format('Y-m') . '-01'))
            : (new \DateTime(end($keys) . '-01'));
        if ($this->from) {
            $fromM = new \DateTime($this->from->format('Y-m') . '-01');
            if ($fromM > $start) $start = $fromM;
        }

        $months = [];
        $cur    = clone $start;
        $guard  = 0;
        while ($cur <= $end && $guard++ < 600) {
            $months[] = $cur->format('Y-m');
            $cur->modify('+1 month');
        }
        return $months;
    }

    /** Sites in site_labels order first, then any code not in that file. */
    private function buildSiteAxis(array $babies): array
    {
        $present = [];
        foreach ($babies as $b) $present[$b['site']] = true;

        $ordered = [];
        foreach (array_keys($this->siteLabels) as $code) {
            if (isset($present[$code])) $ordered[] = $code;
        }
        foreach (array_keys($present) as $code) {
            if (!in_array($code, $ordered, true)) $ordered[] = $code;
        }
        return $ordered;
    }

    // =====================================================================
    // Grids and margins
    // =====================================================================

    private function emptyBucket(): array
    {
        return array_fill_keys(array_keys(self::MEASURES), 0);
    }

    private function emptyGrid(array $sites, array $months): array
    {
        $grid = [];
        foreach ($sites as $s) {
            foreach ($months as $m) $grid[$s][$m] = $this->emptyBucket();
        }
        return $grid;
    }

    private function emptyTypeGrid(array $sites, array $months): array
    {
        $empty = array_fill_keys(array_keys(self::DISCHARGE_TYPES), 0);
        $grid  = [];
        foreach ($sites as $s) {
            foreach ($months as $m) $grid[$s][$m] = $empty;
        }
        return $grid;
    }

    /** Column margin: one bucket per month, summed across sites. */
    private function monthTotals(array $grid, array $months): array
    {
        $out = [];
        foreach ($months as $m) {
            $out[$m] = $this->emptyBucket();
            foreach ($grid as $byMonth) {
                foreach ($this->emptyBucket() as $k => $_) {
                    $out[$m][$k] += $byMonth[$m][$k] ?? 0;
                }
            }
        }
        return $out;
    }

    /** Row margin: one bucket per site, summed across months. */
    private function siteTotals(array $grid): array
    {
        $out = [];
        foreach ($grid as $site => $byMonth) {
            $out[$site] = $this->emptyBucket();
            foreach ($byMonth as $bucket) {
                foreach ($bucket as $k => $v) $out[$site][$k] += $v;
            }
        }
        return $out;
    }

    private function grandTotal(array $grid): array
    {
        $out = $this->emptyBucket();
        foreach ($this->siteTotals($grid) as $bucket) {
            foreach ($bucket as $k => $v) $out[$k] += $v;
        }
        return $out;
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * REDCap returns dates as Y-m-d or Y-m-d H:i. A d/m/Y value would make
     * DateTime throw, so parse explicitly and return null rather than fail
     * the whole report for one bad cell — the count of failures is reported.
     */
    private function parseDate(string $raw): ?\DateTime
    {
        $raw = trim($raw);
        if ($raw === '' || str_starts_with($raw, '99')) return null;   // 99/99/9999 = ongoing

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd/m/Y H:i', 'd/m/Y'] as $fmt) {
            $d = \DateTime::createFromFormat($fmt, $raw);
            if ($d instanceof \DateTime) {
                $errors = \DateTime::getLastErrors();
                if (empty($errors['warning_count']) && empty($errors['error_count'])) {
                    return $d->setTime(0, 0, 0);
                }
            }
        }
        return null;
    }

    private function inPeriod(\DateTime $d): bool
    {
        if ($this->from && $d < $this->from) return false;
        if ($this->to   && $d > $this->to)   return false;
        return true;
    }

    private function monthLabel(string $ym): string
    {
        return (new \DateTime($ym . '-01'))->format('M Y');
    }

    /** Shown on the report so the reader never has to ask what was counted. */
    private function definitionNotes(): array
    {
        return [
            'Enrolled' => 'enr_consent_granted = Yes and enr_datetime present; counted in the month of enr_datetime.',
            'LAMA'     => 'dis_discharge_type = TYP_LAMA; counted in the month of dis_datetime.',
            'DOPR'     => 'dis_discharge_type = TYP_DOPR; counted in the month of dis_datetime.',
            'SAE'      => 'serious_adverse_event form with sae_start_date (Q8) present; counted in the month of sae_start_date.',
            'Rule'     => 'An event is counted in the month of its own date, and only when that date is recorded. Events recorded without a date appear under Data notes, not in the counts.',
            'Bases'    => 'Event basis places an event in the month it happened. Cohort basis places it in the month the baby was enrolled. '
                        . 'The cohort basis can only place an event when the baby has a usable enrollment month, so its totals can be lower; '
                        . 'the difference is given exactly under Data notes and is never a rounding difference.',
        ];
    }

    private function exclusionNotes(): array
    {
        $notes = [];
        if (!$this->includePost28) {
            $notes[] = 'Discharges recorded on the "discharge after 28 days of stay" form are NOT included, '
                     . 'so LAMA and DOPR among babies still in hospital at day 28 are not counted.';
        }
        $notes[] = 'The "discharge of babies denied by physician" form is not included.';
        $notes[] = 'Babies with no enr_hosp_code cannot be placed at a site and are not counted; '
                 . 'the number is given under Data notes.';
        return $notes;
    }
}
