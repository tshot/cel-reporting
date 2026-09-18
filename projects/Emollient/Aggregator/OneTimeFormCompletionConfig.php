<?php

namespace CEL\Projects\Emollient\Aggregator;

/**
 * OneTimeFormCompletionConfig — Parameter Object for OneTimeFormCompletionAggregator.
 *
 * Mirrors the pattern established by FormCompletionConfig:
 *   - All properties are readonly (immutable after construction)
 *   - Validation on construction (formName must not be empty)
 *   - A single fromArray() factory method knows the reports.php key names
 *
 * Constructed by AggregatorFactory via fromArray() — callers never
 * instantiate this directly.
 *
 * Config keys (from reports.php):
 *
 *   form_name           string   REDCap form name to check
 *   form_event          string   Event where the form lives (e.g. 'baseline_arm_1')
 *   enrollment_event    string   Event with enr_consent_granted (default 'day0_arm_1')
 *   discharge_event     string   Event with discharge_form_complete (default 'discharge_arm_1')
 *   other_forms_event   string   Event with pd/sw completion fields (default 'other_forms_arm_1')
 *   date_filter_field   string   Field for period filter (default 'enr_datetime')
 *   date_from           string   Optional lower bound for date filter
 *   date_to             string   Optional upper bound for date filter
 */
final class OneTimeFormCompletionConfig
{
    public readonly string  $formName;
    public readonly string  $formEvent;
    public readonly string  $enrollmentEvent;
    public readonly string  $dischargeEvent;
    public readonly string  $otherFormsEvent;
    public readonly string  $dateFilterField;
    public readonly ?string $dateFrom;
    public readonly ?string $dateTo;
    public readonly bool    $alwaysDue;   // if true, form is always due once consented (discharge/PD/SW don't exempt)
    public readonly ?int    $minAgeDays;  // if set, form is not due until the baby is this many days old
    public readonly string  $dobField;    // field holding date of birth, for minAgeDays

    public function __construct(
        string  $formName,
        string  $formEvent        = 'day0_arm_1',
        string  $enrollmentEvent  = 'day0_arm_1',
        string  $dischargeEvent   = 'discharge_arm_1',
        string  $otherFormsEvent  = 'other_forms_arm_1',
        string  $dateFilterField  = 'enr_datetime',
        ?string $dateFrom         = null,
        ?string $dateTo           = null,
        bool    $alwaysDue        = false,
        ?int    $minAgeDays       = null,
        string  $dobField         = 'enr_baby_dob'
    ) {
        if (empty($formName)) 
        {
            throw new \InvalidArgumentException(
                'OneTimeFormCompletionConfig: formName must not be empty.'
            );
        }

        $this->formName        = $formName;
        $this->formEvent       = $formEvent;
        $this->enrollmentEvent = $enrollmentEvent;
        $this->dischargeEvent  = $dischargeEvent;
        $this->otherFormsEvent = $otherFormsEvent;
        $this->dateFilterField = $dateFilterField;
        $this->dateFrom        = $dateFrom ?: null;
        $this->dateTo          = $dateTo   ?: null;
        $this->alwaysDue       = $alwaysDue;
        $this->minAgeDays      = ($minAgeDays !== null && $minAgeDays > 0) ? $minAgeDays : null;
        $this->dobField        = $dobField;
    }

    /**
     * Factory method: build from the raw $config array in reports.php.
     * This is the only place that knows the array key names.
     */
    public static function fromArray(array $config): self
    {
        return new self(
            formName:       $config['form_name']          ?? '',
            formEvent:      $config['form_event']         ?? 'day0_arm_1',
            enrollmentEvent:$config['enrollment_event']   ?? 'day0_arm_1',
            dischargeEvent: $config['discharge_event']    ?? 'discharge_arm_1',
            otherFormsEvent:$config['other_forms_event']  ?? 'other_forms_arm_1',
            dateFilterField:$config['date_filter_field']  ?? 'enr_datetime',
            dateFrom:       $config['date_from']          ?? null,
            dateTo:          $config['date_to']            ?? null,
            alwaysDue:       (bool)($config['always_due']  ?? false),
            minAgeDays:      isset($config['min_age_days']) ? (int)$config['min_age_days'] : null,
            dobField:        $config['dob_field']           ?? 'enr_baby_dob',
        );
    }
}
