<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AbstractAggregator;
use CEL\Projects\Emollient\Aggregator\OneTimeFormCompletionConfig;

/**
 * OneTimeFormCompletionAggregator
 *
 * Calculates per-participant completion for forms that are filled exactly once
 * (not daily/repeating). Each participant gets a single status:
 *
 *   'complete'  — form_complete = '2'
 *   'missing'   — condition met but form not yet complete
 *   'not_due'   — condition not met (no consent, or stopped early)
 *
 * Eligibility condition (same for all one-time forms in this project):
 *   enr_consent_granted = 'Y'
 *   AND discharge_form_complete        != '2'
 *   AND protocol_deviation_form_complete != '2'
 *   AND study_withdrawal_form_complete   != '2'
 *
 * Config keys (set via reports.php):
 *
 *   form_name           string   REDCap form name to check
 *   form_event          string   Event where the form lives (e.g. 'baseline_arm_1')
 *   enrollment_event    string   Event with enr_consent_granted (default 'day0_arm_1')
 *   discharge_event     string   Event with discharge_form_complete (default 'discharge_arm_1')
 *   other_forms_event   string   Event with pd/sw completion fields (default 'other_forms_arm_1')
 *   date_filter_field   string   Field for period filter (default 'enr_datetime')
 *   date_from / date_to string   Optional date range filter on date_filter_field
 */
class OneTimeFormCompletionAggregator extends AbstractAggregator
{
    private string                     $primaryKey;
    private OneTimeFormCompletionConfig $config;

    public function __construct(string $primaryKey, OneTimeFormCompletionConfig $config)
    {
        $this->primaryKey = $primaryKey;
        $this->config     = $config;
    }

    public function aggregate(iterable $records): array
    {
        // ── Buffer all rows per record ────────────────────────────────────
        $byRecord = [];
        foreach ($records as $row) 
        {
            $id = $row[$this->primaryKey] ?? null;
            if (!$id) continue;
            $byRecord[$id][] = $row;
        }

        $completionField = $this->config->formName . '_complete';
        $participants    = [];

        foreach ($byRecord as $id => $rows) 
        {
            $enrolled        = false;
            $filterDate      = null;
            $site            = null;
            $arm             = null;
            $formComplete    = false;
            $formSeen        = false;
            $discharged      = false;
            $pdDone          = false;
            $swDone          = false;
            $dob             = null;
            $ageDays         = null;

            foreach ($rows as $row) 
            {
                $event = $row['redcap_event_name'] ?? '';

                // ── Enrollment (consent, site, arm, date filter) ──────────
                if ($event === $this->config->enrollmentEvent) 
                {
                    $consent = $row['enr_consent_granted'] ?? '';
                    if ($consent === '1' || $consent === 'Y') $enrolled = true;
                    if (!empty($row['enr_hosp_code']))  $site = $row['enr_hosp_code'];
                    if (!empty($row['enr_study_arm']))  $arm  = $row['enr_study_arm'];
                    if (!empty($row[$this->config->dateFilterField]))
                        $filterDate = $this->parseDate($row[$this->config->dateFilterField]);
                    if (!empty($row[$this->config->dobField]))
                        $dob = $this->parseDate($row[$this->config->dobField]);
                }

                // ── Stop conditions ───────────────────────────────────────
                if ($event === $this->config->dischargeEvent) 
                {
                    if (($row['discharge_form_complete'] ?? '') === '2') $discharged = true;
                }

                if ($event === $this->config->otherFormsEvent) 
                {
                    // Test the datetime, not the _complete flag. A started PD or
                    // SW form means a case exists behind it, so the participant is
                    // closed whether or not the form was finished. The _complete
                    // fields were also never fetched by the report definitions —
                    // only pd_datetime and sw_datetime are — so the previous test
                    // could never fire: Not Due read 0 across 890 participants
                    // while seven completed PD forms existed in REDCap.
                    if (trim((string)($row['pd_datetime'] ?? '')) !== '') $pdDone = true;
                    if (trim((string)($row['sw_datetime'] ?? '')) !== '') $swDone = true;
                }

                // ── Target form ───────────────────────────────────────────
                if ($event === $this->config->formEvent) 
                {
                    $formSeen     = true;
                    $formComplete = ($row[$completionField] ?? '') === '2';
                }
            }

            // ── Must be consented ─────────────────────────────────────────
            if (!$enrolled) continue;

            // ── Date filter on enr_datetime ───────────────────────────────
            if ($filterDate !== null) 
            {
                if ($this->config->dateFrom && $filterDate < new \DateTime($this->config->dateFrom)) continue;
                if ($this->config->dateTo   && $filterDate > (new \DateTime($this->config->dateTo))->setTime(23,59,59)) continue;
            } 
            elseif ($this->config->dateFrom || $this->config->dateTo) 
            {
                continue; // date filter set but field missing — exclude
            }

            // ── Resolve status ───────────────────────────────────────────────────
            //
            // Order of evaluation:
            //   1. If the form is already complete → 'complete' wins, full stop.
            //      (Even if PD/SW happens later, prior completion still counts.)
            //   2. Else, evaluate due-ness:
            //      - always_due mode (SocioEconomic / BaselineGeneral / BaselineAnthro /
            //        MaternalHistory): due unless PD or SW closes the case.
            //        Discharge alone does NOT exempt — it stays 'missing'.
            //      - Standard mode: due only while not discharged and no PD/SW.
            //   3. Otherwise → 'missing'.
            if ($formComplete)
            {
                $status = 'complete';
            }
            else
            {
                if ($this->config->alwaysDue)
                {
                    $due = $enrolled && !$pdDone && !$swDone;
                }
                else
                {
                    $due = $enrolled && !$discharged && !$pdDone && !$swDone;
                }
                $status = $due ? 'missing' : 'not_due';
            }

            // ── Age gate ──────────────────────────────────────────────────
            // A form is only chaseable once enough time has passed for it to
            // have been fillable. Without this, a baby enrolled three days ago
            // counts the same as one whose study period ended a month back, so
            // the missing figure reflects recent enrolment as much as neglect.
            // Opt-in per report via min_age_days; absent for every other form.
            if ($dob !== null)
            {
                $ageDays = (int)$dob->diff(new \DateTime('today'))->format('%r%a');
            }

            $pending = ($status === 'missing');
            if ($pending && $this->config->minAgeDays !== null)
            {
                $pending = ($ageDays !== null && $ageDays >= $this->config->minAgeDays);
            }

            // ── Close reason (informational for always_due) ───────────────
            // Under always_due, PD/SW make the row not_due (handled by notDueReason),
            // so the only useful close-status to surface on a 'missing' row is
            // discharge — it tells the site "baby went home but form wasn't filled".
            $closeReason = null;
            if ($this->config->alwaysDue && $discharged)
            {
                $closeReason = 'Discharged';
            }

            $participants[$id] = [
                'record_id'    => $id,
                'site'         => $site ?? 'Unknown',
                'arm'          => $arm  ?? 'Unknown',
                'status'       => $status,
                'discharged'   => $discharged,
                'pd'           => $pdDone,
                'sw'           => $swDone,
                'close_reason' => $closeReason,
                'enr_date'     => $filterDate?->format('Y-m-d'),
                'dob'          => $dob?->format('Y-m-d'),
                'age_days'     => $ageDays,
                'pending'      => $pending,
                'pending_reason' => $pending
                    ? ($this->config->minAgeDays !== null ? 'Past day ' . $this->config->minAgeDays : 'Due')
                    : ($status === 'missing' ? 'Not yet due' : ''),
            ];
        }

        uasort($participants, fn($a, $b) =>
            [$a['site'], $a['record_id']] <=> [$b['site'], $b['record_id']]
        );

        return [
            'participants' => $participants,
            'site_summary' => $this->buildSiteSummary($participants),
            'form_name'    => $this->config->formName,
            'always_due'   => $this->config->alwaysDue,
            'min_age_days' => $this->config->minAgeDays,
        ];
    }

    // ── Site summary ──────────────────────────────────────────────────────

    private function buildSiteSummary(array $participants): array
    {
        $sites = [];

        foreach ($participants as $p) 
        {
            $s = $p['site'];

            if (!isset($sites[$s])) 
            {
                $sites[$s] = [
                    'site'     => $s,
                    'count'    => 0,
                    'complete' => 0,
                    'missing'  => 0,
                    'pending'  => 0,
                    'not_due'  => 0,
                    'pct'      => null,
                ];
            }

            $sites[$s]['count']++;
            $sites[$s][$p['status']]++;
            if (!empty($p['pending'])) $sites[$s]['pending']++;
        }

        // Compute pct: complete / (complete + pending). A form that is not yet
        // due cannot be counted against a site — before min_age_days was added,
        // recently enrolled babies inflated 'missing' and depressed the
        // percentage, so a site that had just enrolled well scored worst. Where
        // min_age_days is not set, pending equals missing and this is unchanged.
        foreach ($sites as &$s) 
        {
            $denominator = $s['complete'] + ($s['pending'] ?? $s['missing']);
            $s['pct']    = $denominator > 0
                ? round($s['complete'] / $denominator * 100, 1)
                : null;
        }
        unset($s);

        // Total row
        $t = ['site' => 'TOTAL', 'count' => 0, 'complete' => 0, 'missing' => 0, 'pending' => 0, 'not_due' => 0, 'pct' => null];
        foreach ($sites as $s) 
        {
            $t['count']    += $s['count'];
            $t['complete'] += $s['complete'];
            $t['missing']  += $s['missing'];
            $t['pending']  += $s['pending'] ?? 0;
            $t['pending']  += $s['pending'] ?? 0;
            $t['not_due']  += $s['not_due'];
        }
        $denom    = $t['complete'] + $t['pending'];
        $t['pct'] = $denom > 0 ? round($t['complete'] / $denom * 100, 1) : null;

        $sites['TOTAL'] = $t;
        return $sites;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function parseDate(string $raw): ?\DateTime
    {
        if (empty($raw)) return null;
        try { $dt = new \DateTime($raw); $dt->setTime(0,0,0); return $dt; }
        catch (\Exception) { return null; }
    }
}
