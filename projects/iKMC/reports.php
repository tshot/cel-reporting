<?php
/**
 * projects/iKMC/reports.php
 *
 * Report definitions for the iKMC project.
 *
 * Each report references fields by name — the VariablePrefixResolver
 * automatically routes them to the correct source table based on prefix:
 *   scr_  → iKMCv2_EligibilityRegistration
 *   enr_  → iKMCv2_MotherBabyRegistration
 *   dmf_  → iKMCv2_DailyCareTracking
 *   dis_  → iKMCv2_Discharge
 */

return [

    /*
    |--------------------------------------------------------------------------
    | CONSORT — Participant flow diagram
    |--------------------------------------------------------------------------
    | Counts all participants from pre-screening through outcome assessment.
    | Decision tree implemented in ConsortAggregator (see class docblock).
    */
    'Consort' => [
        'title'       => 'CONSORT Diagram',
        'group'       => 'iKMC',
        'mode'        => 'aggregate',
        'aggregator'  => 'consort',
        'exporter'    => 'consort',
        'formats'     => ['html'],
        'fields'      => [
            'recordid',

            // ── Screening (scr_) ────────────────────────────────────────────
            'scr_baby_scrno',
            'scr_birthweight',
            'scr_inf_ga_weeks',
            'scr_status_baby',
            'scr_pob',
            'scr_dof',
            'scr_bwtcut',
            'scr_ga_cut_sncu',
            'scr_bwt_abv_cutoff_lbw',
            'scr_bwt_abv_cutoff_lbw_sick',
            'scr_baby_reach_24hrs',
            'scr_beligenr',
            'scr_mconst',
            'scr_ext1',
            'scr_sp_brth',
            'scr_cong_malf',
            'scr_b_inotrf_2hrs',
            'scr_b_mech_vent_2hrs',

            // ── Enrolment (enr_) ────────────────────────────────────────────
            'enr_dof',

            // ── Discharge (dis_) ────────────────────────────────────────────
            'dis_dof',
            'dis_inf_outcome',
        ],
    ],

];
