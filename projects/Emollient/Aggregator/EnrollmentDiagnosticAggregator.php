<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AggregatorInterface;

/**
 * Diagnostic aggregator — NOT for production dashboards.
 *
 * Outputs one CSV row per record showing every field value used in the
 * enrollment logic, plus a computed STATUS and EXCLUSION_REASON so you
 * can see exactly why each record was counted or skipped.
 *
 * Run with:
 *   php cli.php --project=Emollient --report=EnrollmentDiagnosticAgg --output=diag_agg.csv
 */
class EnrollmentDiagnosticAggregator extends AbstractAggregator
{
    private string $primaryKey;
    private \DateTime $from;
    private \DateTime $to;

    public function __construct(
        string $primaryKey,
        string $dateFrom,
        string $dateTo
    ) 
    {
        $this->primaryKey = $primaryKey;
        $this->from = (new \DateTime($dateFrom))->setTime(0, 0, 0);
        $this->to   = (new \DateTime($dateTo))->setTime(23, 59, 59);
    }

    public function aggregate(iterable $records): array
    {
        // ── Step 1: group rows by record_id ──────────────────────────────────
        $recordsById = [];
        foreach ($records as $row) 
        {
            $id = $row[$this->primaryKey] ?? null;
            if (!$id) continue;
            $recordsById[$id][] = $row;
        }

        $output = [];

        // ── Step 2: evaluate every participant ───────────────────────────────
        foreach ($recordsById as $recordId => $rows) 
        {

            // Raw values from REDCap
            $site             = null;
            $consentRaw       = null;
            $enrDatetimeRaw   = null;
            $enrDate          = null;
            $enrDateParseErr  = null;

            // Derived flags
            $consent          = false;
            $hasDay0Row       = false;

            foreach ($rows as $row) {
                $event = $row['redcap_event_name'] ?? null;

                if ($event === 'day0_arm_1') 
                {
                    $hasDay0Row      = true;
                    $site            = $row['enr_hosp_code']      ?? null;
                    $consentRaw      = $row['enr_consent_granted'] ?? null;
                    $enrDatetimeRaw  = $row['enr_datetime']        ?? null;

                    if ($consentRaw === 'Y' || $consentRaw === '1') 
                    {
                        $consent = true;
                    }

                    if (!empty($enrDatetimeRaw)) 
                    {
                        try 
                        {
                            $enrDate = new \DateTime($enrDatetimeRaw);
                        } catch (\Exception $e) 
                        {
                            $enrDateParseErr = $e->getMessage();
                        }
                    }
                }
            }

            // ── Determine status and exclusion reason ─────────────────────
            $countedMonthly    = false;
            $countedCumulative = false;
            $exclusionReasons  = [];

            if (!$hasDay0Row) 
            {
                $exclusionReasons[] = 'NO_DAY0_ROW';
            } 
            else 
            {
                if (!$site) 
                {
                    $exclusionReasons[] = 'MISSING_SITE';
                }

                if (!$consent) 
                {
                    $exclusionReasons[] = 'CONSENT_NOT_Y (raw value: ' 
                        . var_export($consentRaw, true) . ')';
                }

                if (empty($enrDatetimeRaw)) 
                {
                    $exclusionReasons[] = 'MISSING_ENR_DATETIME';
                } 
                elseif ($enrDateParseErr) 
                {
                    $exclusionReasons[] = 'ENR_DATETIME_PARSE_FAILED: ' . $enrDateParseErr;
                } 
                elseif ($enrDate) 
                {
                    if ($consent && $site) 
                    {
                        if ($enrDate <= $this->to) 
                        {
                            $countedCumulative = true;
                        } 
                        else 
                        {
                            $exclusionReasons[] = 'ENR_DATE_AFTER_TO (' 
                                . $enrDate->format('Y-m-d H:i:s') . ' > ' 
                                . $this->to->format('Y-m-d H:i:s') . ')';
                        }

                        if ($enrDate >= $this->from && $enrDate <= $this->to) 
                        {
                            $countedMonthly = true;
                        } 
                        elseif ($enrDate < $this->from) 
                        {
                            $exclusionReasons[] = 'ENR_DATE_BEFORE_FROM (cumulative only)';
                            // Not an exclusion from cumulative — just clarifying monthly
                            $countedMonthly = false;
                        }
                    }
                }
            }

            $status = 'EXCLUDED';
            if ($countedMonthly)    $status = 'COUNTED_MONTHLY_AND_CUMULATIVE';
            elseif ($countedCumulative) $status = 'COUNTED_CUMULATIVE_ONLY';

            $output[] = [
                'record_id'           => $recordId,
                'site'                => $site                ?? '',
                'enr_consent_raw'     => var_export($consentRaw, true),
                'consent_flag'        => $consent ? 'YES' : 'NO',
                'enr_datetime_raw'    => $enrDatetimeRaw      ?? '',
                'enr_datetime_parsed' => $enrDate 
                                            ? $enrDate->format('Y-m-d H:i:s') 
                                            : ($enrDateParseErr ?? 'NULL'),
                'within_month'        => $countedMonthly    ? 'YES' : 'NO',
                'within_cumulative'   => $countedCumulative ? 'YES' : 'NO',
                'status'              => $status,
                'exclusion_reason'    => implode(' | ', $exclusionReasons),
            ];
        }

        // Sort by site then record_id for easy scanning
        usort($output, fn($a, $b) => 
            [$a['site'], $a['record_id']] <=> [$b['site'], $b['record_id']]
        );

        return $output;
    }
}
