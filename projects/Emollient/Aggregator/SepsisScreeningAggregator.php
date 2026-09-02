<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AbstractAggregator;

/**
 * SepsisScreeningAggregator
 *
 * Completion tracking for neonatal_sepsis_screening form, day1–day28.
 *
 * Due on a given day when EITHER:
 *   A. dcm_vit_status = 'SEPSIS_VS_ALIVE'
 *      AND dcm_baby_discharged is blank or missing
 *   OR
 *   B. dcm_baby_discharged = 'N'
 *
 * Complete = neonatal_sepsis_screening_complete = '2' on the same day event.
 *
 * Stop events (same as other daily forms):
 *   dis_datetime, pd_datetime, sw_datetime, sae_datetime
 */
class SepsisScreeningAggregator extends AbstractAggregator
{
    private string     $primaryKey;
    private array      $siteFilter;
    private ?\DateTime $from;
    private ?\DateTime $to;

    // Per-record accumulators
    private array $site        = [];  // record_id => enr_hosp_code
    private array $arm         = [];  // record_id => enr_study_arm
    private array $enrolled    = [];  // record_id => true
    private array $enrDate     = [];  // record_id => DateTime (enr_datetime)
    private array $dob         = [];  // record_id => DateTime (enr_baby_dob)
    private array $stopDates   = [];  // record_id => [reason => DateTime]

    // Per-record per-day data  [record_id][dayNum] => ['due'=>bool, 'done'=>bool]
    private array $dayData     = [];

    public function __construct(
        string  $primaryKey,
        array   $siteFilter = [],
        ?string $dateFrom   = null,
        ?string $dateTo     = null
    ) {
        $this->primaryKey = $primaryKey;
        $this->siteFilter = array_map('trim', $siteFilter);
        $this->from = $dateFrom ? (new \DateTime($dateFrom))->setTime(0,  0,  0) : null;
        $this->to   = $dateTo   ? (new \DateTime($dateTo  ))->setTime(23, 59, 59) : null;
    }

    public function aggregate(iterable $records): array
    {
        foreach ($records as $row)
        {
            $id = $row[$this->primaryKey] ?? null;
            if (!$id) continue;

            $event      = $row['redcap_event_name']        ?? '';
            $repeatForm = $row['redcap_repeat_instrument'] ?? '';

            // ── Enrollment ─────────────────────────────────────────────────
            if ($event === 'day0_arm_1' && $repeatForm === '')
            {
                $consent = trim($row['enr_consent_granted'] ?? '');
                $hosp    = trim($row['enr_hosp_code']       ?? '');
                if (($consent === 'Y' || $consent === '1') && $hosp !== '') {
                    $this->enrolled[$id] = true;
                    $this->site[$id]     = $hosp;
                    $this->arm[$id]      = trim($row['enr_study_arm'] ?? '');
                }
                if (!empty($row['enr_datetime']))
                    $this->enrDate[$id] = $this->parseDate($row['enr_datetime']);
                if (!empty($row['enr_baby_dob']))
                    $this->dob[$id] = $this->parseDate($row['enr_baby_dob']);
            }

            // ── Stop dates ─────────────────────────────────────────────────
            if ($event === 'discharge_arm_1' && !empty($row['dis_datetime'])) {
                $dt = $this->parseDate($row['dis_datetime']);
                if ($dt) $this->stopDates[$id]['discharge'] = $dt;
            }
            if ($event === 'other_forms_arm_1') {
                foreach (['deviation'=>'pd_datetime','withdrawal'=>'sw_datetime','sae'=>'sae_datetime']
                         as $reason => $field) {
                    if (!empty($row[$field])) {
                        $dt = $this->parseDate($row[$field]);
                        if ($dt) $this->stopDates[$id][$reason] = $dt;
                    }
                }
            }

            // ── Daily events day1–day28 ─────────────────────────────────────
            if (!preg_match('/^day(\d+)_arm_\d+$/', $event, $m)) continue;
            $dayNum = (int)$m[1];
            if ($dayNum < 1 || $dayNum > 28) continue;

            // Due condition (OR logic):
            //   A. dcm_vit_status = 'SEPSIS_VS_ALIVE' AND dcm_baby_discharged blank/missing
            //   B. dcm_baby_discharged = 'N'
            $vitStatus   = trim($row['dcm_vit_status']       ?? '');
            $discharged  = trim($row['dcm_baby_discharged']  ?? '');

            $condA = ($vitStatus === 'SEPSIS_VS_ALIVE') && ($discharged === '');
            $condB = ($discharged === 'N');
            $isDue = $condA || $condB;

            $isDone = ($row['neonatal_sepsis_screening_complete'] ?? '') === '2';

            // Merge into dayData — if any row for this day marks it due/done, keep it
            if (!isset($this->dayData[$id][$dayNum]))
                $this->dayData[$id][$dayNum] = ['due' => false, 'done' => false];

            if ($isDue)  $this->dayData[$id][$dayNum]['due']  = true;
            if ($isDone) $this->dayData[$id][$dayNum]['done'] = true;
        }

        return $this->resolve();
    }

    private function resolve(): array
    {
        $today        = new \DateTime('today');
        $participants = [];
        $bySite       = [];

        foreach ($this->enrolled as $id => $__)
        {
            $site = $this->site[$id] ?? null;
            if ($site === null) continue;

            if (!empty($this->siteFilter)
                && !in_array($site, $this->siteFilter, true)) continue;

            $enrDate = $this->enrDate[$id] ?? null;
            if ($this->from || $this->to) {
                if ($enrDate === null) continue;
                if ($this->from && $enrDate < $this->from) continue;
                if ($this->to   && $enrDate > $this->to)   continue;
            }

            $dob      = $this->dob[$id]      ?? null;
            $stops    = $this->stopDates[$id] ?? [];
            $dayData  = $this->dayData[$id]   ?? [];

            // Compute endDay from DOB
            $refDate     = $dob;
            $daysElapsed = $refDate ? (int)$today->diff($refDate)->days : 28;
            $endDay      = min(28, $daysElapsed);
            foreach ($stops as $stopDt) {
                $stopDay = $refDate ? (int)$stopDt->diff($refDate)->days : $endDay;
                if ($stopDay < $endDay) $endDay = $stopDay;
            }
            $endDay = max(0, $endDay);

            $dueCount  = 0;
            $doneCount = 0;
            $missing   = [];

            for ($d = 1; $d <= $endDay; $d++) {
                $dayRow = $dayData[$d] ?? ['due' => false, 'done' => false];
                if (!$dayRow['due']) continue;  // not due this day — skip
                $dueCount++;
                if ($dayRow['done']) {
                    $doneCount++;
                } else {
                    $missing[] = "D{$d}";
                }
            }

            $pct = $dueCount > 0 ? round($doneCount / $dueCount * 100, 1) : null;

            $participants[$id] = [
                'record_id'  => $id,
                'site'       => $site,
                'arm'        => $this->arm[$id] ?? '',
                'end_day'    => $endDay,
                'due'        => $dueCount,
                'done'       => $doneCount,
                'missing'    => $missing,
                'pct'        => $pct,
            ];

            foreach ([$site, 'Total'] as $grp) {
                if (!isset($bySite[$grp]))
                    $bySite[$grp] = ['enrolled'=>0, 'due'=>0, 'done'=>0];
                $bySite[$grp]['enrolled']++;
                $bySite[$grp]['due']  += $dueCount;
                $bySite[$grp]['done'] += $doneCount;
            }
        }

        // Site percentages
        foreach ($bySite as &$s) {
            $s['pct'] = $s['due'] > 0
                ? round($s['done'] / $s['due'] * 100, 1)
                : null;
        }
        unset($s);

        if (isset($bySite['Total'])) {
            $t = $bySite['Total']; unset($bySite['Total']); $bySite['Total'] = $t;
        }

        return [
            'participants' => $participants,
            'by_site'      => $bySite,
            'period'       => [
                'date_from' => $this->from ? $this->from->format('Y-m-d') : null,
                'date_to'   => $this->to   ? $this->to->format('Y-m-d')   : null,
            ],
        ];
    }

    private function parseDate(string $raw): ?\DateTime
    {
        if (empty($raw)) return null;
        try { $dt = new \DateTime($raw); $dt->setTime(0,0,0); return $dt; }
        catch (\Exception) { return null; }
    }
}
