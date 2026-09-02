<?php

/*
|--------------------------------------------------------------------------
| Shared event lists — avoids repeating the same arrays across reports.
| These constants are used wherever daily or stop events are required.
|
| define() with defined() guards — safe when reports.php is require'd
| multiple times in the same PHP process (e.g. format check + generate).
|--------------------------------------------------------------------------
*/

defined('STOP_EVENTS') || define('STOP_EVENTS', [
    'discharge_arm_1',
    'other_forms_arm_1',
]);

defined('DAILY_EVENTS') || define('DAILY_EVENTS', [
    'day1_arm_1',  'day2_arm_1',  'day3_arm_1',  'day4_arm_1',
    'day5_arm_1',  'day6_arm_1',  'day7_arm_1',  'day8_arm_1',
    'day9_arm_1',  'day10_arm_1', 'day11_arm_1', 'day12_arm_1',
    'day13_arm_1', 'day14_arm_1', 'day15_arm_1', 'day16_arm_1',
    'day17_arm_1', 'day18_arm_1', 'day19_arm_1', 'day20_arm_1',
    'day21_arm_1', 'day22_arm_1', 'day23_arm_1', 'day24_arm_1',
    'day25_arm_1', 'day26_arm_1', 'day27_arm_1', 'day28_arm_1',
]);

// Standard event set for daily completion reports:
// enrollment/DOB event + stop events + all 28 day events.
// array_merge() used instead of spread — spread is not valid in define().
defined('DAILY_COMPLETION_EVENTS') || define('DAILY_COMPLETION_EVENTS',
    array_merge(['day0_arm_1'], STOP_EVENTS, DAILY_EVENTS)
);


return [

    'FullProjectDump' => [
        'group' => 'Diagnostics & Exports',
        'forms' => ['registration','family_details_and_baby_count_form','enrolment_form','baseline_information_module','baseline_anthro_information_module','maternal_history_module','day0_interventionemolliation_module','daily_clinical_monitoring','neonatal_sepsis_screening','skin_condition_scoring','socioeconomic_scale_module','discharge_form','day_28_follow_up_form','protocol_deviation_form','study_withdrawal_form','serious_adverse_event'],
        'mode' => 'wide'
    ],

    'RawLongitudinalDump' => [
        'group' => 'Diagnostics & Exports',
        'forms' => ['registration','family_details_and_baby_count_form','enrolment_form','baseline_information_module','baseline_anthro_information_module','maternal_history_module','day0_interventionemolliation_module','daily_clinical_monitoring','neonatal_sepsis_screening','skin_condition_scoring','socioeconomic_scale_module','discharge_form','day_28_follow_up_form','protocol_deviation_form','study_withdrawal_form','serious_adverse_event'],
        'mode' => 'flat'
    ],

    'RawDump'  => ['mode' => 'raw'],
    'FlatDump' => ['mode' => 'flat'],
    'WideDump' => ['mode' => 'wide'],

    // Wide dump that EXCLUDES repeating forms. One row per subject built only
    // from non-repeating (event-level) forms. Because no repeat-instance
    // detection is needed, this streams without buffering the whole dataset —
    // use it when WideDump exhausts memory.
    'WideDumpNoRepeat'     => ['mode' => 'wide_norepeat'],

    // Same, limited to the 16 core forms (smaller still).
    'FullProjectDumpNoRepeat' => [
        'group' => 'Diagnostics & Exports',
        'forms' => ['registration','family_details_and_baby_count_form','enrolment_form','baseline_information_module','baseline_anthro_information_module','maternal_history_module','day0_interventionemolliation_module','daily_clinical_monitoring','neonatal_sepsis_screening','skin_condition_scoring','socioeconomic_scale_module','discharge_form','day_28_follow_up_form','protocol_deviation_form','study_withdrawal_form','serious_adverse_event'],
        'mode'  => 'wide_norepeat',
    ],

    /*
    |--------------------------------------------------------------------------
    | DIAGNOSTIC
    |--------------------------------------------------------------------------
    */
    'EnrollmentDiagnostic' => [
        'group' => 'Diagnostics & Exports',
        'mode'   => 'raw',
        'events' => ['day0_arm_1'],
        'fields' => ['record_id', 'enr_datetime', 'enr_baby_dob', 'enr_consent_granted', 'enr_hosp_code'],
    ],

    'EnrollmentDiagnosticAgg' => [
        'group' => 'Diagnostics & Exports',
        'mode'       => 'aggregate',
        'aggregator' => 'enrollment_diagnostic',
        'date_from'  => '2026-02-01',
        'date_to'    => '2026-02-28',
        'fields'     => ['enr_datetime', 'enr_baby_dob', 'enr_consent_granted', 'enr_hosp_code'],
        'events'     => ['day0_arm_1'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Eligibility Report - 3 pages
    |
    | Page 1: Eligibility   (baby_prescreening_and_screening_form)
    | Page 2: Enrollment    (enrolment_form)
    | Page 3: Demographics  (screening + socioeconomic_scale_module + GA from baseline)
    |
    | The main fetch covers pages 1-3 in one stream since all three forms
    | share day0_arm_1. GA lives in baseline_arm_1 and is fetched separately
    | via baseline_fetch.
    |--------------------------------------------------------------------------
    */
    'EligibilityReport' => [
        'group' => 'Clinical Reports',
        'mode'             => 'aggregate',
        'aggregator'       => 'eligibility',
        'exporter'         => 'eligibility',
        'site_code_field'  => 'baby_hosp_code',
        'date_to'          => '2026-03-08',
        // CSV not supported — formatted 3-page report, not tabular data
        'formats'          => ['html', 'download_html', 'pdf', 'section'],
        // Page 3 categorical field labels (SES values inside each record)
        'label_map_path'   => __DIR__ . '/label_map.php',
        // Site code → display name (used as column headers and row labels,
        // covers baby_hosp_code on screening form AND enr_hosp_code on
        // enrolment form — both fields share the same hospital code set)
        'site_labels_path' => __DIR__ . '/site_labels.php',
        'forms'  => [
            'baby_prescreening_and_screening_form',
            'enrolment_form',
            'socioeconomic_scale_module',
        ],
        'events' => ['day0_arm_1', 'baseline_arm_1'],
        'fields' => [
            // Page 1 - Eligibility
            'record_id','fmly_livebirth','baby_datetime_birth','baby_datetime','baby_hosp_code','baby_dc_id',
            'baby_admit_nicu','baby_inclusion_brth_wt','baby_current_status',
            'baby_admitted_nicu_24hrs','baby_mech_venti_1','baby_venti_confirmation',
            'baby_mech_venti_2','baby_prescrn_eligible','baby_consent_administered',
            'baby_screen_consent','baby_nicu_weight_elig','baby_oth_study_elig',
            'baby_skin_disease_elig','baby_cong_mal_elig','baby_newborn_surgery',
            'baby_shock','baby_neurological_eligible','baby_other_condition',
            'baby_eligible_enroll',
            // Page 2 - Enrollment detail
            'enr_consent_granted',
            'enr_study_arm',
            'enr_hosp_code',
            'enr_datetime',
            // Page 3 - Demographics (screening + SES)
            'baby_nicu_admit_time_hrs_mins',
            'baby_weight_nicu',
            'ses_religion',
            'ses_caste',
            'ses_age_mthr',
            'ses_age_fthr',
            'ses_mthr_edu_qual',
            'ses_head_edu_qual',
            'ses_fmly_incm',
            'ses_study_arm',
            // Page 3 - GA from baseline_arm_1
            'base_calc_ga_wks',
            'base_calc_ga_days',
        ],
        // GA and study arm live in baseline_arm_1 - fetched as a separate lightweight stream
        'baseline_fetch' => [
            'fields' => ['base_calc_ga_wks', 'base_calc_ga_days', 'base_study_arm'],
            'events' => ['baseline_arm_1'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Form Completion Reports
    | Reuse FormCompletionAggregator by changing form_name in each entry.
    |--------------------------------------------------------------------------
    */
    'DailyMonitoringCompletion' => [
        'group' => 'Form Completion',
        'mode'        => 'aggregate',
        'aggregator'  => 'form_completion',
        'exporter'    => 'form_completion',
        'formats'     => ['html', 'download_html', 'pdf', 'csv', 'excel', 'section'],
        'form_name'   => 'daily_clinical_monitoring',
        'max_days'    => 28,
        'dob_event'        => 'day0_arm_1',
        'enrollment_event' => 'day0_arm_1',
        'discharge_event'  => 'discharge_arm_1',
        'other_forms_event' => 'other_forms_arm_1',
        // Cross-check: if dcm_datetime on the form differs from the event slot
        // it was entered into, credit the form to the day implied by dcm_datetime
        // rather than the event name. This corrects for forms entered into the
        // wrong dayN_arm_1 event in REDCap.
        'day_date_field' => 'dcm_datetime',
        // No depends_on — this is the base form
        'events' => DAILY_COMPLETION_EVENTS,
        'fields' => [
            'record_id',
            'enr_datetime', 'enr_baby_dob', 'enr_consent_granted', 'enr_hosp_code', 'enr_study_arm',
            'dis_datetime',
            'pd_datetime', 'sw_datetime', 'sae_datetime',
            'daily_clinical_monitoring_complete',
            'dcm_datetime',
        ],
    ],

    // Focused variant of DailyMonitoringCompletion above: a flat, downloads-only
    // list of exactly which daily monitoring forms are still outstanding, one
    // row per (baby, missing day) — record_id, event name (Day1..Day28),
    // facility code, due date. Reuses the identical aggregator/config (same
    // 28-day window, same discharge/PD/SW/SAE end-day bounding, same
    // dcm_datetime cross-check) so its numbers always agree with
    // DailyMonitoringCompletion; only the output shape differs, via its own
    // exporter ('missing_daily_monitoring_csv') so that report's existing CSV
    // (record_id/site/arm/day/status) is untouched.
    'MissingDailyMonitoringForm' => [
        'group' => 'Form Completion',
        'mode'        => 'aggregate',
        'aggregator'  => 'form_completion',
        'exporter'    => 'missing_daily_monitoring',
        'formats'     => ['csv'],
        'form_name'   => 'daily_clinical_monitoring',
        'max_days'    => 28,
        'dob_event'        => 'day0_arm_1',
        'enrollment_event' => 'day0_arm_1',
        'discharge_event'  => 'discharge_arm_1',
        'other_forms_event' => 'other_forms_arm_1',
        'day_date_field' => 'dcm_datetime',
        'events' => DAILY_COMPLETION_EVENTS,
        'fields' => [
            'record_id',
            'enr_datetime', 'enr_baby_dob', 'enr_consent_granted', 'enr_hosp_code', 'enr_study_arm',
            'dis_datetime',
            'pd_datetime', 'sw_datetime', 'sae_datetime',
            'daily_clinical_monitoring_complete',
            'dcm_datetime',
        ],
    ],

    // Daily Emolliation: repeating form, 3 sessions per day, Intervention arm only.
    // Day 0 is excluded (sessions_per_day applies from day1_arm_1 onwards).
    // A day is complete only when all 3 instances have _complete = '2'.
    // Daily Emolliation (Intervention arm only, sessions_per_day = 3).
    //
    // Special handling:
    //   day0_form_name   — the one-time Day 0 emolliation form on day0_arm_1.
    //                      Counts as 1 expected session (not 3) for Day 0.
    //                      Days 1-28 each require 3 sessions.
    //
    // Expected total  = 1  (Day 0)  + (days_due × 3)
    // Completed total = count of _complete = '2' instances across all days incl. Day 0
    // Partial         = days where 1 or 2 of 3 sessions are done
    // Missing         = list of "DX#Y" (day X, instance Y) for absent sessions
    'DailyEmolliationCompletion' => [
        'group' => 'Form Completion',
        'mode'           => 'aggregate',
        'aggregator'     => 'form_completion',
        'exporter'       => 'form_completion',
        'formats'        => ['html', 'download_html', 'pdf', 'csv', 'excel', 'section'],
        //'form_name'      => 'day0_interventionemolliation_module',
        'form_name'      => 'daily_interventionemolliation_form',
        'day0_form_name' => 'day0_interventionemolliation_module',  // signals aggregator to include day 0 in loop
        'max_days'       => 28,
        'sessions_per_day' => 3,
        // Only Intervention arm babies receive emolliation
        'base_conditions' => [
            ['field' => 'enr_study_arm', 'value' => 'Intervention'],
        ],
        'dob_event'          => 'day0_arm_1',
        'enrollment_event'   => 'day0_arm_1',
        'discharge_event'    => 'discharge_arm_1',
        'other_forms_event'  => 'other_forms_arm_1',
        // No depends_on — independent of Daily Monitoring
        'events' => DAILY_COMPLETION_EVENTS,
        'fields' => [
            'record_id',
            'enr_datetime', 'enr_baby_dob', 'enr_consent_granted', 'enr_hosp_code', 'enr_study_arm',
            'dis_datetime', 'pd_datetime', 'sw_datetime', 'sae_datetime',
            'day0_interventionemolliation_module_complete', 'daily_interventionemolliation_form_complete',
            'fi_emol_perid',   // MOR/AFT/EVE — determines Day 0 expected session count
        ],
    ],

    // Skin Scoring is only due on Day 3, 7, 14, 28 AND only when the same-day
    // Daily Monitoring shows SEPSIS_VS_ALIVE and baby is not yet discharged.
    // scheduled_days restricts which events are checked.
    // field_conditions checks dcm_vit_status on the same event row.
    // dcm_baby_discharged = '' or 'N' is the default when not discharged —
    // a non-blank 'Y' value means discharged; we treat any non-'Y' as due.
    'SkinScoringCompletion' => [
        'group' => 'Form Completion',
        'mode'        => 'aggregate',
        'aggregator'  => 'form_completion',
        'exporter'    => 'form_completion',
        'formats'     => ['html', 'download_html', 'pdf', 'csv', 'excel', 'section'],
        'form_name'   => 'skin_condition_scoring',
        'max_days'    => 28,
        'dob_event'        => 'day0_arm_1',
        'enrollment_event' => 'day0_arm_1',
        'discharge_event'  => 'discharge_arm_1',
        'other_forms_event' => 'other_forms_arm_1',
        // Only due on these four days
        'scheduled_days' => [3, 7, 14, 28],
        // Cross-check: if scs_datetime on the form differs from the event slot
        // it was entered into, credit the form to the day implied by scs_datetime.
        'day_date_field' => 'scs_datetime',
        // Only due when same-day monitoring shows sepsis alive AND baby not discharged
        'field_conditions' => [
            ['field' => 'dcm_vit_status',      'value' => 'SEPSIS_VS_ALIVE', 'op' => 'eq'],
            ['field' => 'dcm_baby_discharged',  'value' => 'Y',               'op' => 'neq'],
        ],
        // No depends_on — condition is now handled via field_conditions above
        'events' => [
            'day0_arm_1', 'discharge_arm_1', 'other_forms_arm_1',
            'day3_arm_1', 'day7_arm_1', 'day14_arm_1', 'day28_arm_1',
        ],
        'fields' => [
            'record_id',
            'enr_datetime', 'enr_baby_dob', 'enr_consent_granted', 'enr_hosp_code', 'enr_study_arm',
            'dis_datetime', 'pd_datetime', 'sw_datetime', 'sae_datetime',
            'skin_condition_scoring_complete',
            'scs_datetime',                 // form's own date field for re-mapping
            'dcm_vit_status',               // needed for field_conditions check
            'dcm_baby_discharged',          // needed to confirm not discharged
        ],
    ],

    // Neonatal Sepsis Screening also depends on Daily Monitoring.
    'SepsisScreeningCompletion' => [
        'group' => 'Form Completion',
        'mode'        => 'aggregate',
        'aggregator'  => 'form_completion',
        'exporter'    => 'form_completion',
        'formats'     => ['html', 'download_html', 'pdf', 'csv', 'excel', 'section'],
        'form_name'   => 'neonatal_sepsis_screening',
        'max_days'    => 28,
        'dob_event'        => 'day0_arm_1',
        'enrollment_event' => 'day0_arm_1',
        'discharge_event'  => 'discharge_arm_1',
        'other_forms_event' => 'other_forms_arm_1',
        'depends_on'  => [
            ['form' => 'daily_clinical_monitoring', 'type' => 'daily_parent'],
        ],
        // Cross-check: if nss_datetime on the form differs from the event slot
        // it was entered into, credit the form to the day implied by nss_datetime.
        'day_date_field' => 'nss_datetime',
        // Form is only due on a day where same-day daily monitoring explicitly
        // recorded dcm_baby_discharged = 'N'. Any other value (including blank
        // or 'Y') marks the day as 'not_due'.
        'field_conditions' => [
            ['field' => 'dcm_baby_discharged', 'value' => 'N', 'op' => 'eq'],
        ],
        'events' => DAILY_COMPLETION_EVENTS,
        'fields' => [
            'record_id',
            'enr_datetime', 'enr_baby_dob', 'enr_consent_granted', 'enr_hosp_code', 'enr_study_arm',
            'dis_datetime', 'pd_datetime', 'sw_datetime', 'sae_datetime',
            'neonatal_sepsis_screening_complete',
            'nss_datetime',                         // form's own date field for re-mapping
            'daily_clinical_monitoring_complete',   // needed for dependency check
            'dcm_baby_discharged',                  // needed for field_conditions check
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | One-Time Form Completion Reports
    |
    | Eligibility condition (shared by all four):
    |   enr_consent_granted = 'Y'
    |   AND discharge_form_complete          != '2'
    |   AND protocol_deviation_form_complete != '2'
    |   AND study_withdrawal_form_complete   != '2'
    |
    | Socioeconomic Scale Module  → day0_arm_1
    | Baseline General Info       → baseline_arm_1  (consent fetched from day0_arm_1)
    | Baseline Anthro Info        → baseline_arm_1
    | Maternal History Module     → baseline_arm_1
    |--------------------------------------------------------------------------
    */
    'SocioeconomicCompletion' => [
        'group' => 'Form Completion',
        'mode'               => 'aggregate',
        'aggregator'         => 'one_time_form_completion',
        'exporter'           => 'one_time_completion',
        'always_due'         => true,      // must be completed even if discharged; only PD/SW exempt
        'formats'            => ['html', 'download_html', 'pdf', 'csv', 'excel'],
        'form_name'          => 'socioeconomic_scale_module',
        'form_event'         => 'day0_arm_1',
        'enrollment_event'   => 'day0_arm_1',
        'discharge_event'    => 'discharge_arm_1',
        'other_forms_event'  => 'other_forms_arm_1',
        'events' => [
            'day0_arm_1',
            'discharge_arm_1',
            'other_forms_arm_1',
        ],
        'fields' => [
            'record_id',
            'enr_datetime', 'enr_consent_granted', 'enr_hosp_code', 'enr_study_arm',
            'discharge_form_complete',
            'protocol_deviation_form_complete',
            'study_withdrawal_form_complete',
            'socioeconomic_scale_module_complete',
        ],
    ],

    'BaselineGeneralCompletion' => [
        'group' => 'Form Completion',
        'mode'               => 'aggregate',
        'aggregator'         => 'one_time_form_completion',
        'exporter'           => 'one_time_completion',
        'always_due'         => true,      // must be completed even if discharged; only PD/SW exempt
        'formats'            => ['html', 'download_html', 'pdf', 'csv', 'excel'],
        'form_name'          => 'baseline_information_module',
        'form_event'         => 'baseline_arm_1',
        'enrollment_event'   => 'day0_arm_1',       // consent lives here
        'discharge_event'    => 'discharge_arm_1',
        'other_forms_event'  => 'other_forms_arm_1',
        'events' => [
            'day0_arm_1',           // enr_consent_granted, enr_datetime
            'baseline_arm_1',       // the form itself
            'discharge_arm_1',
            'other_forms_arm_1',
        ],
        'fields' => [
            'record_id',
            'enr_datetime', 'enr_consent_granted', 'enr_hosp_code', 'enr_study_arm',
            'discharge_form_complete',
            'protocol_deviation_form_complete',
            'study_withdrawal_form_complete',
            'baseline_information_module_complete',
        ],
    ],

    'BaselineAnthroCompletion' => [
        'group' => 'Form Completion',
        'mode'               => 'aggregate',
        'aggregator'         => 'one_time_form_completion',
        'exporter'           => 'one_time_completion',
        'always_due'         => true,      // must be completed even if discharged; only PD/SW exempt
        'formats'            => ['html', 'download_html', 'pdf', 'csv', 'excel'],
        'form_name'          => 'baseline_anthro_information_module',
        'form_event'         => 'baseline_arm_1',
        'enrollment_event'   => 'day0_arm_1',
        'discharge_event'    => 'discharge_arm_1',
        'other_forms_event'  => 'other_forms_arm_1',
        'events' => [
            'day0_arm_1',
            'baseline_arm_1',
            'discharge_arm_1',
            'other_forms_arm_1',
        ],
        'fields' => [
            'record_id',
            'enr_datetime', 'enr_consent_granted', 'enr_hosp_code', 'enr_study_arm',
            'discharge_form_complete',
            'protocol_deviation_form_complete',
            'study_withdrawal_form_complete',
            'baseline_anthro_information_module_complete',
        ],
    ],

    'MaternalHistoryCompletion' => [
        'group' => 'Form Completion',
        'mode'               => 'aggregate',
        'aggregator'         => 'one_time_form_completion',
        'exporter'           => 'one_time_completion',
        'always_due'         => true,      // must be completed even if discharged; only PD/SW exempt
        'formats'            => ['html', 'download_html', 'pdf', 'csv', 'excel'],
        'form_name'          => 'maternal_history_module',
        'form_event'         => 'baseline_arm_1',
        'enrollment_event'   => 'day0_arm_1',
        'discharge_event'    => 'discharge_arm_1',
        'other_forms_event'  => 'other_forms_arm_1',
        'events' => [
            'day0_arm_1',
            'baseline_arm_1',
            'discharge_arm_1',
            'other_forms_arm_1',
        ],
        'fields' => [
            'record_id',
            'enr_datetime', 'enr_consent_granted', 'enr_hosp_code', 'enr_study_arm',
            'discharge_form_complete',
            'protocol_deviation_form_complete',
            'study_withdrawal_form_complete',
            'maternal_history_module_complete',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | DISCHARGE FORM COMPLETION
    |--------------------------------------------------------------------------
    | One-time form on discharge_arm_1. Due for every enrolled baby once
    | they are discharged (or reach Day 28 still in hospital).
    |
    | Unlike other one-time forms, discharge_form_complete is NOT used as
    | a stop/exclusion criterion — it is the form we are tracking.
    | Stop triggers are: protocol_deviation, study_withdrawal, SAE.
    |
    | A baby is included once enr_consent_granted = Y.
    | The form_event = 'discharge_arm_1' tells the aggregator where to
    | look for the completion field.
    |--------------------------------------------------------------------------
    */
    'DischargeFormCompletion' => [
        'group'              => 'Form Completion',
        'mode'               => 'aggregate',
        'aggregator'         => 'one_time_form_completion',
        'exporter'           => 'one_time_completion',
        'formats'            => ['html', 'download_html', 'pdf', 'csv', 'excel'],
        'form_name'          => 'discharge_form',
        'form_event'         => 'discharge_arm_1',
        'enrollment_event'   => 'day0_arm_1',
        'discharge_event'    => 'discharge_arm_1',
        'other_forms_event'  => 'other_forms_arm_1',
        'events' => [
            'day0_arm_1',           // enr_consent_granted, enr_datetime, enr_hosp_code
            'discharge_arm_1',      // discharge_form_complete + dis_datetime live here
            'other_forms_arm_1',    // pd_datetime, sw_datetime, sae_datetime
        ],
        'fields' => [
            'record_id',
            'enr_datetime', 'enr_baby_dob', 'enr_consent_granted', 'enr_hosp_code', 'enr_study_arm',
            // dis_datetime — used as a stop date by the aggregator (baby left the study)
            // Note: discharge_form_complete is NOT a stop trigger because it IS
            // the form being tracked. Stop triggers are dis_datetime, PD, SW, SAE.
            'dis_datetime',
            'pd_datetime', 'sw_datetime', 'sae_datetime',
            'discharge_form_complete',
        ],
    ],

        'EligibilityDiagnostic' => [
        'group' => 'Diagnostics & Exports',
        'mode'       => 'aggregate',
        'aggregator' => 'eligibility_diagnostic',
        'date_to'    => '2026-03-10',
        'forms'      => ['baby_prescreening_and_screening_form'],
        'events'     => ['day0_arm_1'],
        'fields'     => [
            'record_id','baby_datetime_birth','baby_hosp_code','baby_admit_nicu',
            'baby_inclusion_brth_wt','baby_current_status','baby_admitted_nicu_24hrs',
            'baby_mech_venti_1','baby_venti_confirmation','baby_mech_venti_2',
            'baby_prescrn_eligible','baby_consent_administered','baby_screen_consent',
            'baby_nicu_weight_elig','baby_oth_study_elig','baby_skin_disease_elig',
            'baby_cong_mal_elig','baby_newborn_surgery','baby_shock',
            'baby_neurological_eligible','baby_other_condition','baby_eligible_enroll',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Enrollment Reports
    |--------------------------------------------------------------------------
    */
    'MonthlyDashboard' => [
        'group' => 'Clinical Reports',
        'mode'            => 'aggregate',
        'aggregator'      => 'monthly_enrollment',
        'exporter'        => 'monthly_dashboard',
        'site_code_field' => 'enr_hosp_code',
        'date_from'       => '2026-02-01',
        'date_to'         => '2026-02-28',
        'fields'          => [
            'enr_datetime','enr_consent_granted','enr_hosp_code',
            'dis_datetime','dis_discharge_type','int_no_emol_reason',
        ],
    ],

    'WeeklyEnrollmentChart' => [
        'group' => 'Clinical Reports',
        'mode'             => 'aggregate',
        'aggregator'       => 'weekly_enrollment',
        'exporter'         => 'weekly_chart',
        'site_code_field'  => 'enr_hosp_code',
        'site_labels_path' => __DIR__ . '/site_labels.php',
        'date_from' => '2026-02-01',
        'date_to'   => '2026-02-28',
        'fields'    => ['enr_datetime', 'enr_baby_dob', 'enr_consent_granted', 'enr_hosp_code'],
    ],

    'MonthlyEnrollmentChart' => [
        'group' => 'Clinical Reports',
        'mode'             => 'aggregate',
        'aggregator'       => 'monthly_enrollment_chart',
        'exporter'         => 'monthly_chart',
        'site_code_field'  => 'enr_hosp_code',
        'site_labels_path' => __DIR__ . '/site_labels.php',
        'date_from' => '2025-09-01',
        'date_to'   => '2026-02-28',
        'fields'    => ['enr_datetime', 'enr_baby_dob', 'enr_consent_granted', 'enr_hosp_code'],
    ],

    /*
    |--------------------------------------------------------------------------
    | LENGTH OF STAY
    |--------------------------------------------------------------------------
    | Three variants sharing the same aggregator:
    |   LengthOfStay          — all sites, all arms
    |   LengthOfStaySite      — filtered by ?sites=GSVM,JSS in URL
    |   LengthOfStayDiagnostic— raw per-patient CSV dump for data review
    |
    | Fields fetched:
    |   day0_arm_1        baby_eligible_enroll, baby_datetime_admission
    |   discharge_arm_1   dis_hosp_code, dis_study_arm, dis_in_hosp, dis_datetime
    |
    | URL parameters accepted at runtime (override these defaults):
    |   ?date_from=YYYY-MM-DD
    |   ?date_to=YYYY-MM-DD
    |   ?sites=GSVM,JSS,NILOU   (comma-separated site codes)
    |--------------------------------------------------------------------------
    */

    'LengthOfStay' => [
        'group' => 'Clinical Reports',
        'mode'       => 'aggregate',
        'aggregator' => 'length_of_stay',
        'exporter'   => 'length_of_stay',
        'formats'    => ['html', 'download_html', 'pdf', 'csv', 'section'],
        'forms'      => [
            'baby_prescreening_and_screening_form',
            'discharge_form',
        ],
        'fields'     => [
            // Screening form (day0_arm_1) — twin/triplet identification
            'baby_eligible_enroll',
            'baby_datetime_admission',
            // Discharge form (discharge_arm_1) — site, arm, and discharge data
            'dis_hosp_code',
            'dis_study_arm',
            'dis_in_hosp',
            'dis_datetime',
        ],
        'events'     => [
            'day0_arm_1',
            'discharge_arm_1',
        ],
        // No date_from / date_to — defaults to all dates.
        // Pass ?date_from= and ?date_to= in the URL to filter.
        // No site_filter — defaults to all sites.
        // Pass ?sites=GSVM,JSS to filter by site.
    ],

    // LengthOfStayBySite has been removed — it was identical to LengthOfStay.
    // To filter by site, use: ?project=Emollient&report=LengthOfStay&format=html&sites=GSVM,JSS


    // Diagnostic / raw dump — CSV only, no HTML rendering.
    // Useful for data managers to audit LOS calculations in a spreadsheet.
    'LengthOfStayDiagnostic' => [
        'group' => 'Diagnostics & Exports',
        'mode'       => 'aggregate',
        'aggregator' => 'length_of_stay',
        'exporter'   => 'csv',           // CsvExporter handles the patients array
        'formats'    => ['csv'],
        'forms'      => [
            'baby_prescreening_and_screening_form',
            'discharge_form',
        ],
        'fields'     => [
            'baby_eligible_enroll',
            'baby_datetime_admission',
            'dis_hosp_code',
            'dis_study_arm',
            'dis_in_hosp',
            'dis_datetime',
        ],
        'events'     => [
            'day0_arm_1',
            'discharge_arm_1',
        ],
    ],


    /*
    |--------------------------------------------------------------------------
    | WEIGHT ANALYSIS
    |--------------------------------------------------------------------------
    | WeightAnalysis    — full 8-section interactive report (all sites, all arms)
    | WeightDiagnostic  — per-patient CSV dump for data managers
    |
    | Events fetched:
    |   day0_arm_1          baby_eligible_enroll, baby_birth_wt_hosp,
    |                       baby_weight_nicu, baby_sex, baby_hosp_code
    |   day1_arm_1 …        dcm_newborn_weight_1, dcm_newborn_weight_2
    |   day28_arm_1
    |   discharge_arm_1     dis_baby_weight_1, dis_baby_weight_2,
    |                       dis_hosp_code, dis_study_arm
    |
    | URL parameters accepted:
    |   ?date_from=YYYY-MM-DD  ?date_to=YYYY-MM-DD  ?sites=CODE,CODE
    |--------------------------------------------------------------------------
    */

    'WeightAnalysis' => [
        'group' => 'Clinical Reports',
        'mode'       => 'aggregate',
        'aggregator' => 'weight_analysis',
        'exporter'   => 'weight_analysis',
        'formats'    => ['html', 'download_html', 'pdf', 'csv', 'section'],
        'forms'      => [
            'baby_prescreening_and_screening_form',
            'daily_clinical_monitoring',
            'discharge_form',
        ],
        'fields'     => [
            // Day 0 — Screening form (enrolled baby identification)
            'baby_eligible_enroll',
            'baby_birth_wt_hosp',
            'baby_weight_nicu',
            'baby_sex',
            'baby_hosp_code',
            // Daily monitoring form (days 1–28)
            'dcm_newborn_weight_1',
            'dcm_newborn_weight_2',
            // Discharge form
            'dis_baby_weight_1',
            'dis_baby_weight_2',
            'dis_hosp_code',
            'dis_study_arm',
        ],
        'events'     => array_merge(
            ['day0_arm_1'],
            DAILY_EVENTS,
            ['discharge_arm_1']
        ),
    ],

    // CSV-only diagnostic dump — per-patient raw weight values
    'WeightDiagnostic' => [
        'group' => 'Diagnostics & Exports',
        'mode'       => 'aggregate',
        'aggregator' => 'weight_analysis',
        'exporter'   => 'csv',
        'formats'    => ['csv'],
        'forms'      => [
            'baby_prescreening_and_screening_form',
            'daily_clinical_monitoring',
            'discharge_form',
        ],
        'fields'     => [
            'baby_eligible_enroll',
            'baby_birth_wt_hosp',
            'baby_weight_nicu',
            'baby_sex',
            'baby_hosp_code',
            'dcm_newborn_weight_1',
            'dcm_newborn_weight_2',
            'dis_baby_weight_1',
            'dis_baby_weight_2',
            'dis_hosp_code',
            'dis_study_arm',
        ],
        'events'     => array_merge(
            ['day0_arm_1'],
            DAILY_EVENTS,
            ['discharge_arm_1']
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | WEIGHT ANALYSIS
    |--------------------------------------------------------------------------
    | Sources:
    |   day0_arm_1           baby_eligible_enroll, baby_birth_wt_hosp,
    |                        baby_weight_nicu, baby_sex, baby_hosp_code
    |   day1_arm_1..day28    dcm_newborn_weight_1, dcm_newborn_weight_2
    |   discharge_arm_1      dis_baby_weight_1, dis_baby_weight_2,
    |                        dis_hosp_code, dis_study_arm, dis_in_hosp, dis_datetime
    |
    | Weight = avg(w1, w2). All values in grams. LOS mirrors LOS aggregator.
    | Growth velocity = Fenton/ESPGHAN formula (g/kg/day).
    | Healthy preterm range: 15-20 g/kg/day.
    |--------------------------------------------------------------------------
    */

    'WeightAnalysis' => [
        'mode'       => 'aggregate',
        'aggregator' => 'weight_analysis',
        'exporter'   => 'weight_analysis',
        'formats'    => ['html', 'download_html', 'pdf', 'csv', 'section'],
        'forms'      => [
            'baby_prescreening_and_screening_form',
            'daily_clinical_monitoring',
            'discharge_form',
        ],
        'fields'     => [
            // Screening form (day0_arm_1)
            'baby_eligible_enroll',
            'baby_birth_wt_hosp',
            'baby_weight_nicu',
            'baby_sex',
            'baby_hosp_code',
            'baby_datetime_admission',
            // Daily monitoring (day1_arm_1 - day28_arm_1)
            'dcm_newborn_weight_1',
            'dcm_newborn_weight_2',
            // Discharge form (discharge_arm_1)
            'dis_baby_weight_1',
            'dis_baby_weight_2',
            'dis_hosp_code',
            'dis_study_arm',
            'dis_in_hosp',
            'dis_datetime',
        ],
        'events'     => array_merge(['day0_arm_1', 'discharge_arm_1'], DAILY_EVENTS),
    ],

    // Diagnostic CSV: raw per-patient dump for data managers
    'WeightAnalysisDiagnostic' => [
        'group' => 'Diagnostics & Exports',
        'mode'       => 'aggregate',
        'aggregator' => 'weight_analysis',
        'exporter'   => 'csv',
        'formats'    => ['csv'],
        'forms'      => [
            'baby_prescreening_and_screening_form',
            'daily_clinical_monitoring',
            'discharge_form',
        ],
        'fields'     => [
            'baby_eligible_enroll', 'baby_birth_wt_hosp', 'baby_weight_nicu',
            'baby_sex', 'baby_hosp_code', 'baby_datetime_admission',
            'dcm_newborn_weight_1', 'dcm_newborn_weight_2',
            'dis_baby_weight_1', 'dis_baby_weight_2',
            'dis_hosp_code', 'dis_study_arm', 'dis_in_hosp', 'dis_datetime',
        ],
        'events'     => array_merge(['day0_arm_1', 'discharge_arm_1'], DAILY_EVENTS),
    ],

    /*
    |--------------------------------------------------------------------------
    | EMOLLIATION COVERAGE — PI Presentation Table
    |--------------------------------------------------------------------------
    | Site | Enrolled | Received Full Intervention (%) | Stopped (%)
    |
    | Enrolled           = Intervention arm babies who gave consent
    | Full Intervention  = every daily_interventionemolliation_form session
    |                      has int_emoliate_baby = 'Y'
    | Stopped            = stop event before Day 28 (discharge/PD/SW/SAE)
    |--------------------------------------------------------------------------
    */
    'EmolliationCoverage' => [
        'group' => 'Clinical Reports',
        'mode'             => 'aggregate',
        'aggregator'       => 'emolliation_coverage',
        'chunk_size'       => 50,   // heavy fetch: 31 events + repeating form
        'exporter'         => 'emol_coverage',
        'formats'          => ['html', 'download_html', 'pdf', 'csv', 'section'],
        'site_labels_path' => __DIR__ . '/site_labels.php',
        'forms'            => [
            'enrolment_form',
            'day0_interventionemolliation_module', 
            'daily_interventionemolliation_form',
            'discharge_form',
            'protocol_deviation_form',
            'study_withdrawal_form',
            'serious_adverse_event',
        ],
        'fields'           => [
            // Enrollment
            'enr_consent_granted',
            'enr_hosp_code',
            'enr_study_arm',
            'enr_datetime',
            'enr_baby_dob',
            'fi_emol_perid',       // MOR/AFT/EVE — Day 0 expected session count
            // Data collector (from prescreening form)
            'baby_dc_id',
            // Emolliation application status (repeating form)
            'int_emoliate_baby',
            // Emolliation application status (Non Repeating form -- First Emolliatiuon form)
            'day0_interventionemolliation_module_complete',  // ← add this — guards day0 form row
            // Stop dates
            'dis_datetime',
            'pd_datetime',
            'sw_datetime',
            'sae_datetime',
        ],
        'events' => array_merge(
            ['day0_arm_1', 'discharge_arm_1', 'other_forms_arm_1'],
            DAILY_EVENTS
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | EMOLLIATION COVERAGE FOR DASHBOARD
    |--------------------------------------------------------------------------
    | Same full coverage report as EmolliationCoverage (Due / Attempted /
    | Given / Not Given across Days 0-28) with two differences:
    |
    |   1. Day 0 counts as a FLAT 1 (not 1/2/3 by fi_emol_perid). Day 0 is
    |      "done" when the Day 0 module is complete OR a Day 0 daily form has
    |      int_emoliate_baby = 'Y'.
    |   2. Adds a No-Emolliation Reason breakdown for every Not-Given session
    |      (int_emoliate_baby = 'N'), from int_no_emol_reason and its sub-lists.
    |
    | Extra fields vs EmolliationCoverage: the reason hierarchy fields.
    |
    | Access:
    |   ?project=Emollient&report=EmolliationCoverageForDashboard&format=html
    |--------------------------------------------------------------------------
    */
    'EmolliationCoverageForDashboard' => [
        'group' => 'Clinical Reports',
        'mode'             => 'aggregate',
        'aggregator'       => 'emolliation_coverage_dashboard',
        'chunk_size'       => 50,   // heavy fetch: 31 events + repeating form
        'exporter'         => 'emol_coverage_dashboard',
        'formats'          => ['html', 'download_html', 'pdf', 'csv', 'section'],
        'site_labels_path' => __DIR__ . '/site_labels.php',
        'forms'            => [
            'enrolment_form',
            'day0_interventionemolliation_module',
            'daily_interventionemolliation_form',
            'discharge_form',
            'protocol_deviation_form',
            'study_withdrawal_form',
            'serious_adverse_event',
        ],
        'fields'           => [
            // Enrollment
            'enr_consent_granted',
            'enr_hosp_code',
            'enr_study_arm',
            'enr_datetime',
            'enr_baby_dob',
            'fi_emol_perid',       // still fetched (module-complete fallback), not used for weighting
            // Data collector (from prescreening form)
            'baby_dc_id',
            // Emolliation application status (repeating form)
            'int_emoliate_baby',
            // No-Emolliation reason hierarchy (NEW)
            'int_no_emol_reason',            // master reason
            'int_doctor_deny_reason_list',   // MD sub-list
            'int_doctor_deny_othr_reason',   // MD -> OTH_DOC_DENY free text
            'int_sae_reasons',               // SAE sub-list (checkbox set: int_sae_reasons___*)
            'int_other_no_emoll_reason',     // OTH_NOEMOL free text
            // Day 0 module completion guard
            'day0_interventionemolliation_module_complete',
            // Stop dates
            'dis_datetime',
            'pd_datetime',
            'sw_datetime',
            'sae_datetime',
        ],
        'events' => array_merge(
            ['day0_arm_1', 'discharge_arm_1', 'other_forms_arm_1'],
            DAILY_EVENTS
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | EMOLLIATION FLAT EXPORT
    |--------------------------------------------------------------------------
    | One row per baby. Session columns named:  d{N}_s{S}_{field}
    |
    |   d0_s1  = day0_interventionemolliation_module (non-repeating Day 0 form)
    |   d0_s2  = daily_interventionemolliation_form instance 1 on day0_arm_1
    |   d0_s3  = daily_interventionemolliation_form instance 2 on day0_arm_1
    |   d1_s1..d28_s3 = repeating instances on day1_arm_1..day28_arm_1
    |
    | fields_filter: leave empty to include all form fields (default).
    |   To restrict, set e.g. ['int_emoliate_baby','int_no_emol_reason']
    |--------------------------------------------------------------------------
    */
    /*
    |--------------------------------------------------------------------------
    | EMOLLIATION WIDE EXPORT
    |--------------------------------------------------------------------------
    | One row per baby — uses the built-in wide transformer pipeline.
    | No custom aggregator needed.
    |
    | Column naming (WidePivotTransformer):
    |   Non-repeating : {event}_{form}_{field}
    |   Repeating     : {event}_{form}_{instance}_{field}
    |
    | Examples:
    |   day0_arm_1_day0_interventionemolliation_module_fi_emol_perid
    |   day0_arm_1_daily_interventionemolliation_form_1_int_emoliate_baby
    |   day3_arm_1_daily_interventionemolliation_form_2_int_emoliate_baby
    |   day3_arm_1_daily_interventionemolliation_form_2_daily_interventionemolliation_form_complete
    |
    | CLI:
    |   php tools/dump.php --project=Emollient --report=EmolliationWide --output=emol.csv
    |
    | Browser CSV:
    |   http://reports.local/index.php?project=Emollient&report=EmolliationWide&format=csv
    |--------------------------------------------------------------------------
    */
    'EmolliationWide' => [
        'group' => 'Diagnostics & Exports',
        'mode'            => 'wide',
        'chunk_size'      => 50,
        // Only include babies where at least one emolliation form was filled
        // Filter: only babies where at least one emolliation session has int_emoliate_baby filled
        // Using int_emoliate_baby instead of _complete since complete fields
        // may not appear in REDCap metadata for repeating instruments.
        'filter_nonempty' => ['int_emoliate_baby', 'fi_emol_perid'],
        // select_fields: empty = all columns. Substrings matched against column names.
        // e.g. ['int_emoliate_baby','int_no_emol_reason','fi_emol_perid','_complete']
        // to get only those fields across all days/sessions.
        // Restrict to emolliation fields only — excludes enr_* fields
        // that leak in from enrolment_form sharing the day0_arm_1 event.
        // select_fields: empty = all ~3748 columns (high memory).
        // Restrict to reduce memory and get a manageable CSV.
        // Each substring is matched against the full column name.
        // Key clinical fields only (~300 columns instead of 3748):
        'select_fields'   => [
            'int_emoliate_baby',     // was emolliation given? (Y/N)
            'int_no_emol_reason',    // why not given
            'int_emol_period',       // morning/afternoon/evening
            'fi_emol_perid',         // day0 enrollment period
        ],   // small chunks — wide mode buffers ALL records in memory
        'forms' => [
            'day0_interventionemolliation_module',
            'daily_interventionemolliation_form',
        ],
        'events' => array_merge(
            ['day0_arm_1'],
            DAILY_EVENTS
        ),
        // Explicit fields forces REDCap to return per-event longitudinal rows
        // with redcap_event_name populated. Without this, REDCap collapses
        // all events into a single flat row per record.
        // Add any additional fields from these forms as needed.
        'fields' => [
            'record_id',
            // Enrolment
            'enr_hosp_code', 'enr_study_arm', 'enr_datetime',
            'enr_baby_dob', 'enr_consent_granted',
            // Day 0 emolliation form fields
            'fi_emol_perid', 'fi_emol_day', 'fi_conditions',
            'fi_pre_emol_weight', 'fi_oil_qty', 'fi_oil_qty_drops',
            'fi_mp_test', 'fi_oil_applied', 'fi_status_post_emol',
            'fi_skin_changes___psc_nsc', 'fi_skin_changes___psc_rash',
            'fi_skin_changes___psc_blt', 'fi_skin_changes___psc_edm',
            'fi_sign_obsrvd___nos', 'fi_sign_obsrvd___red',
            'fi_behavior_changes', 'fi_serious_sign', 'fi_remarks',
            'day0_interventionemolliation_module_complete',
            // Daily emolliation form fields
            'int_study_arm', 'int_hosp_code', 'int_datetime',
            'int_emoliate_baby', 'int_no_emol_reason',
            'int_emol_period', 'int_emol_day', 'int_conditions',
            'int_pre_emol_weight', 'int_fi_oil_qty', 'int_fi_oil_qty_drops',
            'int_status_post_emol', 'int_remarks', 'int_emol_remarks',
            'int_sae_reasons___nnd', 'int_sae_reasons___svr_hpr_sensitivity',
            'int_sae_reasons___svr_skin_inf', 'int_sae_reasons___minor_rash',
            'int_sae_reasons___skin_irritation',
            'int_daily_asses', 'int_emolliation_instance',
            'daily_interventionemolliation_form_complete',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | DATA COLLECTOR REPORT
    |--------------------------------------------------------------------------
    | One report, form selector dropdown, site filter.
    | Currently covers: baby_prescreening_and_screening_form (baby_dc_id)
    | Each form page shows: exclusion breakdown per data collector + chart
    |
    | Extend by adding more forms to DataCollectorAggregator and
    | DataCollectorHtmlExporter as the study progresses.
    |
    | Access:
    |   http://reports.local/index.php?project=Emollient&report=DataCollectorReport&format=html
    |   http://reports.local/index.php?project=Emollient&report=DataCollectorReport&format=html&sites=GSVM
    |--------------------------------------------------------------------------
    */
    'DataCollectorReport' => [
        'group' => 'Quality & Audit',
        'mode'             => 'aggregate',
        'aggregator'       => 'data_collector',
        'exporter'         => 'data_collector',
        'formats'          => ['html', 'download_html', 'pdf', 'section'],
        'site_labels_path' => __DIR__ . '/site_labels.php',
        'wt_bin'           => 50,   // birth weight histogram bin size in grams
        'forms'            => ['baby_prescreening_and_screening_form'],
        'events'           => ['day0_arm_1'],
        'fields'           => [
            'record_id',
            'baby_dc_id',
            'baby_hosp_code',
            'baby_datetime_birth',
            // Pre-screening fields
            'baby_admit_nicu',
            'baby_inclusion_brth_wt',
            'baby_current_status',
            'baby_admitted_nicu_24hrs',
            'baby_mech_venti_1',
            'baby_venti_confirmation',
            'baby_mech_venti_2',
            'baby_oth_study_elig',
            'baby_prescrn_eligible',
            // Screening exclusion fields
            'baby_consent_administered',
            'baby_screen_consent',
            'baby_nicu_weight_elig',
            'baby_oth_study_scrng_elig',
            'baby_skin_disease_elig',
            'baby_cong_mal_elig',
            'baby_newborn_surgery',
            'baby_shock',
            'baby_neurological_eligible',
            'baby_other_condition',
            'baby_eligible_enroll',
            'baby_weight_nicu',       // NICU admission weight (grams) for histogram
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | DATA COLLECTOR REPORT
    |--------------------------------------------------------------------------
    | One page per form (form selector dropdown).
    | First version: Prescreening & Screening form (baby_dc_id).
    | Each page: exclusion metrics table + bar chart by data collector.
    |
    | Extend by adding more forms to this report and adding their
    | aggregation logic to DataCollectorAggregator.
    |
    | Access:
    |   ?project=Emollient&report=DataCollectorReport&format=html
    |   ?project=Emollient&report=DataCollectorReport&format=html&sites=GSVM
    |   ?project=Emollient&report=DataCollectorReport&format=section&section=screening
    |--------------------------------------------------------------------------
    */
    'DataCollectorReport' => [
        'mode'             => 'aggregate',
        'aggregator'       => 'data_collector',
        'exporter'         => 'data_collector',
        'formats'          => ['html', 'download_html', 'pdf', 'section'],
        'site_labels_path' => __DIR__ . '/site_labels.php',
        'forms'            => ['baby_prescreening_and_screening_form'],
        'events'           => ['day0_arm_1'],
        'fields'           => [
            'record_id',
            'baby_hosp_code',
            'baby_dc_id',
            'baby_datetime_birth',
            'baby_admit_nicu',
            'baby_inclusion_brth_wt',
            'baby_current_status',
            'baby_admitted_nicu_24hrs',
            'baby_mech_venti_1',
            'baby_venti_confirmation',
            'baby_mech_venti_2',
            'baby_oth_study_elig',
            'baby_prescrn_eligible',
            'baby_consent_administered',
            'baby_screen_consent',
            'baby_nicu_weight_elig',
            'baby_oth_study_scrng_elig',
            'baby_skin_disease_elig',
            'baby_cong_mal_elig',
            'baby_newborn_surgery',
            'baby_shock',
            'baby_neurological_eligible',
            'baby_other_condition',
            'baby_eligible_enroll',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | WEEKLY MEETING REPORT
    |--------------------------------------------------------------------------
    | Site-wise summary table for weekly internal meetings.
    |
    | Metrics:
    |   Admission Weight  : baby_weight_nicu 700-1800g and 1000-1499g
    |   PreScreened       : baby_prescreening_and_screening_form_complete = '2'
    |   Screened          : baby_prescrn_eligible = 'Yes'
    |   Enrollment        : enr_study_arm = Control / Intervention
    |   Discharge         : discharge_form_complete, dis_discharge_type
    |   Protocol Deviation: protocol_deviation_form_complete not blank
    |   29-day Follow-up  : fu28_day28_fup = 'DONE'
    |
    | Access:
    |   ?project=Emollient&report=WeeklyMeeting&format=html
    |   ?project=Emollient&report=WeeklyMeeting&format=html&sites=GSVM,JSS
    |   ?project=Emollient&report=WeeklyMeeting&format=html&date_from=2026-01-01&date_to=2026-01-31
    |--------------------------------------------------------------------------
    */
    'WeeklyMeeting' => [
        'group' => 'Clinical Reports',
        'mode'             => 'aggregate',
        'aggregator'       => 'weekly_meeting',
        'exporter'         => 'weekly_meeting',
        'formats'          => ['html', 'download_html', 'csv', 'excel'],
        'site_labels_path' => __DIR__ . '/site_labels.php',
        'events'           => ['day0_arm_1', 'discharge_arm_1', 'other_forms_arm_1', 'day29_arm_1'],
        'fields'           => [
            'record_id',
            // Site fields
            'baby_hosp_code', 'enr_hosp_code', 'dis_hosp_code', 'pd_hosp_code', 'fu28_hosp_name',

            // ── day0_arm_1 repeating (baby_prescreening_and_screening_form) ──
            'baby_datetime',
            'baby_datetime_birth',
            // Pre-screening exclusion fields (reused from EligibilityMetricDefinitions)
            'baby_birth_wt_hosp',
            'baby_admit_nicu',
            'baby_inclusion_brth_wt',
            'baby_current_status',
            'baby_admitted_nicu_24hrs',
            'baby_mech_venti_1',
            'baby_venti_confirmation',
            'baby_mech_venti_2',
            'baby_oth_study_elig',
            'baby_prescrn_eligible',
            // Screening exclusion fields
            'baby_consent_administered',
            'baby_screen_consent',
            'baby_nicu_weight_elig',
            'baby_oth_study_scrng_elig',
            'baby_skin_disease_elig',
            'baby_cong_mal_elig',
            'baby_newborn_surgery',
            'baby_shock',
            'baby_neurological_eligible',
            'baby_other_condition',
            'baby_eligible_enroll',

            // ── day0_arm_1 non-repeating (enrollment) ──
            'enr_datetime',
            'enr_consent_granted',
            'enr_study_arm',

            // ── discharge_arm_1 ──
            'dis_datetime',
            'discharge_form_complete',
            'dis_discharge_type',
            'dis_in_hosp',
            'discharge_after_28_days_of_stay_complete',

            // ── other_forms_arm_1 (protocol deviation / SAE / withdrawal) ──
            'pd_datetime',
            'protocol_deviation_form_complete',
            'sae_datetime',
            'sw_datetime',

            // ── day29_arm_1 (29-day follow-up) ──
            'fu28_datetime',
            'fu28_day28_fup',
            'fu28_alive_day28',
        ],
    ],



    /*
    |--------------------------------------------------------------------------
    | CLINICAL OUTCOMES
    |--------------------------------------------------------------------------
    | Primary and secondary clinical outcomes for the Emollient trial.
    |
    | Denominators:
    |   N1 = all enrolled (enr_study_arm != '' on day0_arm_1)
    |   N2 = N1 minus LAMA / Referral / Abscond / DOPR discharge types
    |
    | Events fetched:
    |   day0_arm_1          — enrollment, birth anthropometrics
    |   day1_arm_1 to       — daily sepsis screening (nss fields)
    |   day28_arm_1
    |   discharge_arm_1     — discharge type, age, anthropometrics
    |   day29_arm_1         — 29-day follow-up (neonatal mortality)
    |   day3/7/14/28_arm_1  — skin condition scores (already in day range)
    |--------------------------------------------------------------------------
    */
    'Clinical_Outcomes' => [
        'group'       => 'Clinical Outcomes',
        'mode'        => 'aggregate',
        'aggregator'  => 'clinical_outcomes',
        'exporter'    => 'clinical_outcomes',
        'formats'     => ['html', 'download_html', 'pdf'],
        'events'      => [
            'baseline_arm_1',
            'day0_arm_1',
            'day1_arm_1',  'day2_arm_1',  'day3_arm_1',  'day4_arm_1',
            'day5_arm_1',  'day6_arm_1',  'day7_arm_1',  'day8_arm_1',
            'day9_arm_1',  'day10_arm_1', 'day11_arm_1', 'day12_arm_1',
            'day13_arm_1', 'day14_arm_1', 'day15_arm_1', 'day16_arm_1',
            'day17_arm_1', 'day18_arm_1', 'day19_arm_1', 'day20_arm_1',
            'day21_arm_1', 'day22_arm_1', 'day23_arm_1', 'day24_arm_1',
            'day25_arm_1', 'day26_arm_1', 'day27_arm_1', 'day28_arm_1',
            'discharge_arm_1',
            'day29_arm_1',
        ],
        'fields'      => [
            'record_id',

            // ── Enrollment (day0_arm_1) ──────────────────────────────────
            'enr_study_arm',
            'enr_hosp_code',

            // ── Birth anthropometrics (day0_arm_1) ───────────────────────
            'baby_birth_wt_hosp',
            'base_anthro_head_circumference_1',
            'base_anthro_head_circumference_2',
            'base_anthro_muac_1',
            'base_anthro_muac_2',
            'base_skin_cond_score',
            'baby_hosp_code',

            // ── Sepsis screening (day0–day28, non-repeating per event) ───
            'nss_high_temp',
            'nss_low_temp',
            'nss_rr',
            'nss_neuro_sign___sepsis_neuro_seizure',
            'nss_tlc',
            'nss_anc',
            'nss_micro_esr',
            'nss_crp',

            // ── Skin condition scores (day0/3/7/14/28) ───────────────────
            'scs_hosp_code',
            'scs_condition',

            // ── Discharge (discharge_arm_1) ──────────────────────────────
            'dis_hosp_code',
            'dis_study_arm',
            'dis_discharge_type',
            'dis_post_28_discharge_type',
            'dis_in_hosp',
            'dis_age_discharge',
            'dis_post_28_age_discharge',
            'dis_baby_weight_1',
            'dis_baby_weight_2',
            'dis_head_circumference_1',
            'dis_head_circumference_2',
            'dis_muac_1',
            'dis_muac_2',

            // ── 29-day follow-up (day29_arm_1) ───────────────────────────
            'fu28_alive_day28',
            'fu28_study_arm',
        ],
    ],

];
