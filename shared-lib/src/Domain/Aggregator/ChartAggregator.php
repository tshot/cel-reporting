<?php

namespace CEL\Shared\Domain\Aggregator;

/**
 * ChartAggregator (abstract base)
 *
 * Shared aggregation logic for all period-wise, site-wise enrollment charts.
 * Handles REDCap record grouping, consent/date/site extraction, and tallying.
 *
 * Subclasses declare only what differs between chart variants:
 *   - buildPeriods()  — returns ordered array of ['label', 'start', 'end'] buckets
 *   - periodsKey()    — the key used in the returned payload ('weeks' / 'months')
 */
abstract class ChartAggregator extends AbstractAggregator
{
    protected string    $primaryKey;
    protected \DateTime $from;
    protected \DateTime $to;
    protected array     $siteLabels;

    public function __construct(
        string $primaryKey,
        string $dateFrom,
        string $dateTo,
        array  $siteLabels = []
    ) 
    {
        $this->primaryKey = $primaryKey;
        $this->from       = (new \DateTime($dateFrom))->setTime(0,  0,  0);
        $this->to         = (new \DateTime($dateTo  ))->setTime(23, 59, 59);
        $this->siteLabels = $siteLabels;
    }

    abstract protected function buildPeriods(): array;
    abstract protected function periodsKey(): string;

    public function aggregate(iterable $records): array
    {
        $recordsById = [];
        foreach ($records as $row)
        {
            $id = $row[$this->primaryKey] ?? null;
            if (!$id) continue;
            $recordsById[$id][] = $row;
        }

        $periods = $this->buildPeriods();
        $counts  = [];
        $sites   = [];

        foreach ($recordsById as $rows)
        {
            $site    = null;
            $enrDate = null;
            $consent = false;

            foreach ($rows as $row)
            {
                if (($row['redcap_event_name'] ?? null) !== 'day0_arm_1') continue;

                $siteVal = $row['enr_hosp_code'] ?? null;
                if (!empty($siteVal)) $site = $siteVal;

                $cv = $row['enr_consent_granted'] ?? null;
                if ($cv === '1' || $cv === 'Y') $consent = true;

                $raw = $row['enr_datetime'] ?? null;
                if (!empty($raw))
                {
                    try { $enrDate = new \DateTime($raw); }
                    catch (\Exception) { $enrDate = null; }
                }
            }

            if (!$consent || !$enrDate || !$site) continue;
            if ($enrDate < $this->from || $enrDate > $this->to) continue;

            $periodLabel = $this->resolvePeriodLabel($enrDate, $periods);
            if (!$periodLabel) continue;

            $displaySite = $this->siteLabels[$site] ?? $site;
            $counts[$displaySite][$periodLabel] = ($counts[$displaySite][$periodLabel] ?? 0) + 1;

            if (!in_array($displaySite, $sites, true))
            {
                $sites[] = $displaySite;
            }
        }

        // Order sites by site_labels sequence, then append any unlabelled ones.
        $orderedSites = [];
        foreach (array_values($this->siteLabels) as $displayName)
        {
            if (in_array($displayName, $sites, true))
                $orderedSites[] = $displayName;
        }
        foreach ($sites as $s)
        {
            if (!in_array($s, $orderedSites, true))
                $orderedSites[] = $s;
        }
        $sites = $orderedSites;

        $periodLabels = array_column($periods, 'label');
        $siteTotals   = [];
        $grandTotal   = 0;

        foreach ($sites as $site)
        {
            $siteTotal         = array_sum($counts[$site] ?? []);
            $siteTotals[$site] = $siteTotal;
            $grandTotal       += $siteTotal;
        }

        $periodTotals = [];
        foreach ($periodLabels as $label)
        {
            $sum = 0;
            foreach ($sites as $site)
            {
                $sum += $counts[$site][$label] ?? 0;
            }
            $periodTotals[$label] = $sum;
        }

        return [
            $this->periodsKey() => $periodLabels,
            'sites'             => $sites,
            'counts'            => $counts,
            'site_totals'       => $siteTotals,
            'period_totals'     => $periodTotals,
            'grand_total'       => $grandTotal,
            'date_from'         => $this->from->format('d M Y'),
            'date_to'           => $this->to->format('d M Y'),
        ];
    }

    private function resolvePeriodLabel(\DateTime $date, array $periods): ?string
    {
        foreach ($periods as $period)
        {
            if ($date >= $period['start'] && $date <= $period['end'])
            {
                return $period['label'];
            }
        }
        return null;
    }
}
