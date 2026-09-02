<?php

namespace CEL\Projects\Emollient\Aggregator;

/**
 * FormCompletionConfig — Parameter Object for FormCompletionAggregator.
 *
 * Replaces the 16-parameter constructor with a single value object.
 * All validation and defaulting happens here, keeping the aggregator
 * focused on aggregation logic only.
 *
 * Constructed by AggregatorFactory from the $config array read out of
 * reports.php — callers never instantiate this directly.
 */
final class FormCompletionConfig
{
    // ── What to check ──────────────────────────────────────────────────────
    public readonly string  $formName;
    public readonly array   $siteFilter;     // empty = all sites
    public readonly int     $maxDays;
    public readonly int     $sessionsPerDay;
    public readonly array   $baseConditions;
    public readonly array   $scheduledDays;
    public readonly array   $fieldConditions;
    public readonly array   $dependencies;
    public readonly ?string $day0FormName;    // one-time Day 0 form (emolliation only)

    // ── Where to find data ─────────────────────────────────────────────────
    public readonly string $dateFilterField;
    public readonly string $birthDateField;
    public readonly string $dobEvent;
    public readonly string $enrollmentEvent;
    public readonly string $dischargeEvent;
    public readonly string $otherFormsEvent;

    // ── Date range ─────────────────────────────────────────────────────────
    public readonly ?string $dateFrom;
    public readonly ?string $dateTo;
    public readonly string  $dayDateField;   // optional date field on the form, e.g. dcm_datetime

    public function __construct(
        string  $formName,
        int     $maxDays         = 28,
        int     $sessionsPerDay  = 1,
        array   $baseConditions  = [],
        array   $scheduledDays   = [],
        array   $fieldConditions = [],
        array   $dependencies    = [],
        ?string $day0FormName    = null,
        array   $siteFilter      = [],
        string  $dateFilterField = 'enr_datetime',
        string  $birthDateField  = 'enr_baby_dob',
        string  $dobEvent        = 'day0_arm_1',
        string  $enrollmentEvent = 'day0_arm_1',
        string  $dischargeEvent  = 'discharge_arm_1',
        string  $otherFormsEvent = 'other_forms_arm_1',
        ?string $dateFrom        = null,
        ?string $dateTo          = null,
        string  $dayDateField    = ''
    ) 
    {
        if (empty($formName)) 
        {
            throw new \InvalidArgumentException('FormCompletionConfig: formName must not be empty.');
        }
        if ($maxDays < 1) 
        {
            throw new \InvalidArgumentException('FormCompletionConfig: maxDays must be at least 1.');
        }

        $this->formName        = $formName;
        $this->maxDays         = $maxDays;
        $this->sessionsPerDay  = max(1, $sessionsPerDay);
        $this->baseConditions  = $baseConditions;
        $this->scheduledDays   = $scheduledDays;
        $this->fieldConditions = $fieldConditions;
        $this->dependencies    = $dependencies;
        $this->day0FormName    = $day0FormName ?: null;
        $this->siteFilter      = $siteFilter;
        $this->dateFilterField = $dateFilterField;
        $this->birthDateField  = $birthDateField;
        $this->dobEvent        = $dobEvent;
        $this->enrollmentEvent = $enrollmentEvent;
        $this->dischargeEvent  = $dischargeEvent;
        $this->otherFormsEvent = $otherFormsEvent;
        $this->dateFrom        = $dateFrom ?: null;
        $this->dateTo          = $dateTo   ?: null;
        $this->dayDateField    = $dayDateField;
    }

    /**
     * Factory method: build from the raw $config array in reports.php.
     * This is the only place that knows the array key names.
     */
    public static function fromArray(array $config): self
    {
        return new self(
            formName:        $config['form_name']          ?? 'daily_clinical_monitoring',
            maxDays:         $config['max_days']            ?? 28,
            sessionsPerDay:  $config['sessions_per_day']   ?? 1,
            baseConditions:  $config['base_conditions']    ?? [],
            scheduledDays:   $config['scheduled_days']     ?? [],
            fieldConditions: $config['field_conditions']   ?? [],
            dependencies:    $config['depends_on']         ?? [],
            day0FormName:    $config['day0_form_name']     ?? null,
            siteFilter:      $config['site_filter']        ?? [],
            dateFilterField: $config['date_filter_field']  ?? 'enr_datetime',
            birthDateField:  $config['birth_date_field']   ?? 'enr_baby_dob',
            dobEvent:        $config['dob_event']          ?? 'day0_arm_1',
            enrollmentEvent: $config['enrollment_event']   ?? 'day0_arm_1',
            dischargeEvent:  $config['discharge_event']    ?? 'discharge_arm_1',
            otherFormsEvent: $config['other_forms_event']  ?? 'other_forms_arm_1',
            dateFrom:        $config['date_from']          ?? null,
            dateTo:          $config['date_to']            ?? null,
            dayDateField:    $config['day_date_field']     ?? '',
        );
    }
}
