<?php

namespace CEL\Projects\Emollient\Support;

/**
 * SiteResolver
 *
 * DRY utility — resolves the hospital site code from a REDCap row.
 *
 * The Emollient project stores the hospital code in different fields
 * depending on the event and form:
 *
 *   day0_arm_1  repeating  → baby_hosp_code
 *   day0_arm_1  non-rep    → enr_hosp_code
 *   discharge_arm_1        → dis_hosp_code
 *   other_forms_arm_1      → pd_hosp_code
 *   day29_arm_1            → fu28_hosp_name
 *
 * This pattern was duplicated across EligibilityAggregator,
 * DataCollectorAggregator, and WeeklyMeetingAggregator — a DRY violation.
 *
 * Usage:
 *   $site = SiteResolver::fromRow($row, $event, $repeatForm);
 *
 *   // With per-record tracking (site from earlier events reused on later ones):
 *   $resolver = new SiteResolver();
 *   $site = $resolver->resolve($id, $row, $event, $repeatForm);
 */
class SiteResolver
{
    /**
     * Site code seen per record_id — reused for events with no site field.
     * (e.g. other_forms_arm_1 and day29_arm_1 may have no site field if
     *  the site was already recorded on an earlier event for the same record.)
     */
    private array $cache = [];

    /**
     * Resolve site code for a row, with per-record caching.
     *
     * Once a site is resolved for a record_id, it is stored and returned
     * for subsequent rows of the same record that lack a site field.
     */
    public function resolve(
        string $recordId,
        array  $row,
        string $event,
        string $repeatForm = ''
    ): string 
    {
        $site = self::fromRow($row, $event, $repeatForm);
        if ($site !== '') {
            $this->cache[$recordId] = $site;
            return $site;
        }
        return $this->cache[$recordId] ?? '';
    }

    /**
     * Stateless site extraction from a single row.
     *
     * Returns empty string if no site field is populated on this row.
     */
    public static function fromRow(
        array  $row,
        string $event      = '',
        string $repeatForm = ''
    ): string 
    {
        // Event-specific primary site fields
        $candidate = match (true) {
            $event === 'day0_arm_1' && $repeatForm !== '' =>
                trim($row['baby_hosp_code'] ?? ''),

            $event === 'day0_arm_1' && $repeatForm === '' =>
                trim($row['enr_hosp_code'] ?? ''),

            $event === 'discharge_arm_1' =>
                trim($row['dis_hosp_code'] ?? ''),

            $event === 'other_forms_arm_1' =>
                trim($row['pd_hosp_code'] ?? ''),

            $event === 'day29_arm_1' =>
                trim($row['fu28_hosp_name'] ?? ''),

            default => '',
        };

        if ($candidate !== '') return $candidate;

        // Fallback: try all known site fields in priority order
        foreach (['enr_hosp_code', 'baby_hosp_code', 'dis_hosp_code',
                  'pd_hosp_code', 'fu28_hosp_name'] as $f) {
            $v = trim($row[$f] ?? '');
            if ($v !== '') return $v;
        }

        return '';
    }

    /** Clear the per-record cache (useful between report runs). */
    public function reset(): void
    {
        $this->cache = [];
    }
}
