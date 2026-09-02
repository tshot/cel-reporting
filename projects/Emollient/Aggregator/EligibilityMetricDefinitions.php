<?php

namespace CEL\Projects\Emollient\Aggregator;

/**
 * EligibilityMetricDefinitions
 *
 * Value class — pure data, no logic.
 *
 * metrics()    — per-row callable conditions
 * metricMeta() — display metadata (label, section, denominator)
 *
 * Design:
 *   Single exclusion metrics — exactly one criterion fails, all others pass.
 *   Multiple exclusion (multiple_prscrn_exclusion / others_with_wt) — two or
 *   more criteria fail simultaneously. The conditions are mutually exclusive
 *   by construction so no baby is double-counted.
 *
 *   ventiEligibility() — shared helper encoding the three-field ventilator
 *   rule. Returns 'Yes' (eligible = not on ventilator), 'No' (not eligible =
 *   on ventilator), or '' (unanswered). Used by four metric conditions.
 *
 *   Denominators — baby_prescrn_eligible uses N = babies_prescreened,
 *   baby_eligible_enroll uses N = baby_prescrn_eligible, so % columns
 *   render correctly in the exporter.
 */
final class EligibilityMetricDefinitions
{
    /**
     * Per-row metric conditions.
     * Returns [ metric_key => callable(array $row): bool ].
     */
    public static function metrics(): array
    {
        return [

/* ── PreScreening ─────────────────────────────────────────────────────────── */

            'babies_prescreened' => fn($r) =>
                !empty($r['redcap_repeat_instance'])
                && (int)$r['redcap_repeat_instance'] > 0,

            // Exclusion: baby NOT admitted to NICU (baby_admit_nicu = 'N').
            // If REDCap stores a different value (e.g. '0', 'No'), update here.
            // Run tools/diag_nicu_values.php to check actual stored values.
            'baby_admit_nicu' => fn($r) =>
                ($r['baby_admit_nicu'] ?? '') === 'N',

            'birth_weight_no' => fn($r) =>
                ($r['baby_inclusion_brth_wt'] ?? '') === 'No'
                && ($r['baby_current_status']   ?? '') === 'PRESCRN_ALIVE'
                && ($r['baby_admitted_nicu_24hrs'] ?? '') === 'Yes'
                && self::ventiEligiblity($r) === 'Yes',

            'baby_current_status' => fn($r) =>
                ($r['baby_current_status']     ?? '') === 'PRESCRN_DEAD'
                && ($r['baby_inclusion_brth_wt'] ?? '') === 'Yes',

            'baby_admitted_nicu_24hrs' => fn($r) =>
                ($r['baby_admitted_nicu_24hrs'] ?? '') === 'No'
                && self::ventiEligiblity($r) === 'Yes'
                && ($r['baby_inclusion_brth_wt'] ?? '') === 'Yes',

            'baby_mech_venti' => fn($r) =>
                self::ventiEligiblity($r) === 'No'
                && ($r['baby_admitted_nicu_24hrs'] ?? '') === 'Yes'
                && ($r['baby_inclusion_brth_wt']   ?? '') === 'Yes',

            'baby_other_study' => fn($r) =>
                ($r['baby_oth_study_elig'] ?? '') === 'No',

            'multiple_prscrn_exclusion' => fn($r) =>
                (($r['baby_current_status']     ?? '') === 'PRESCRN_DEAD'
                && ($r['baby_inclusion_brth_wt'] ?? '') === 'No')
            ||
				(($r['baby_inclusion_brth_wt'] ?? '') === 'No')
				&& ($r['baby_admitted_nicu_24hrs'] ?? '') === 'No'
			||
				(($r['baby_inclusion_brth_wt'] ?? '') === 'No')
				&& self::ventiEligiblity($r) === 'No'
			||
                (($r['baby_admitted_nicu_24hrs'] ?? '') === 'No'
                && self::ventiEligiblity($r) === 'No'),
			
            'twins' => fn($r) =>
                ($r['baby_admitted_nicu_24hrs'] ?? '') === 'Yes'
                && ($r['baby_inclusion_brth_wt'] ?? '') === 'Yes'
                && (
                    $r['baby_oth_study_elig'] === 'Yes'
                    || $r['baby_oth_study_elig'] === ''
                )
                && self::ventiEligiblity($r) === 'Yes'
                && ($r['baby_prescrn_eligible'] ?? '') === 'No',

            'baby_prescrn_eligible' => fn($r) =>
                ($r['baby_prescrn_eligible'] ?? '') === 'Yes',

/* ── Screening ────────────────────────────────────────────────────────────── */

            'baby_consent_administered' => fn($r) =>
                ($r['baby_consent_administered'] ?? '') === 'N',

            'baby_screen_consent' => fn($r) =>
                ($r['baby_screen_consent'] ?? '') === 'N',

            'baby_nicu_weight_elig' => fn($r) =>
                ($r['baby_nicu_weight_elig']       ?? '') === 'No'
                && (($r['baby_oth_study_scrng_elig'] ?? '') === 'Yes' || ($r['baby_oth_study_scrng_elig'] ?? '') === '')
                && ($r['baby_skin_disease_elig']     ?? '') === 'Yes'
                && ($r['baby_cong_mal_elig']         ?? '') === 'Yes'
                && ($r['baby_newborn_surgery']       ?? '') === 'N'
                && ($r['baby_shock']                 ?? '') === 'N'
                && ($r['baby_neurological_eligible'] ?? '') === 'Yes',

            'baby_oth_study_scrng_elig' => fn($r) =>
                ($r['baby_oth_study_scrng_elig']   ?? '') === 'No'
                && ($r['baby_nicu_weight_elig']       ?? '') === 'Yes'
                && ($r['baby_skin_disease_elig']     ?? '') === 'Yes'
                && ($r['baby_cong_mal_elig']         ?? '') === 'Yes'
                && ($r['baby_newborn_surgery']       ?? '') === 'N'
                && ($r['baby_shock']                 ?? '') === 'N'
                && ($r['baby_neurological_eligible'] ?? '') === 'Yes',

            'baby_skin_disease_elig' => fn($r) =>
                ($r['baby_skin_disease_elig']      ?? '') === 'No'
                && ($r['baby_nicu_weight_elig']      ?? '') === 'Yes'
                && (($r['baby_oth_study_scrng_elig'] ?? '') === 'Yes' || ($r['baby_oth_study_scrng_elig'] ?? '') === '')
                && ($r['baby_cong_mal_elig']         ?? '') === 'Yes'
                && ($r['baby_newborn_surgery']       ?? '') === 'N'
                && ($r['baby_shock']                 ?? '') === 'N'
                && ($r['baby_neurological_eligible'] ?? '') === 'Yes',

            'baby_cong_mal_elig' => fn($r) =>
                ($r['baby_cong_mal_elig']          ?? '') === 'No'
                && ($r['baby_skin_disease_elig']     ?? '') === 'Yes'
                && ($r['baby_nicu_weight_elig']      ?? '') === 'Yes'
                && (($r['baby_oth_study_scrng_elig'] ?? '') === 'Yes' || ($r['baby_oth_study_scrng_elig'] ?? '') === '')
                && ($r['baby_newborn_surgery']       ?? '') === 'N'
                && ($r['baby_shock']                 ?? '') === 'N'
                && ($r['baby_neurological_eligible'] ?? '') === 'Yes',

            'baby_newborn_surgery' => fn($r) =>
                ($r['baby_newborn_surgery']        ?? '') === 'Y'
                && ($r['baby_cong_mal_elig']         ?? '') === 'Yes'
                && ($r['baby_skin_disease_elig']     ?? '') === 'Yes'
                && ($r['baby_nicu_weight_elig']      ?? '') === 'Yes'
                && (($r['baby_oth_study_scrng_elig'] ?? '') === 'Yes' || ($r['baby_oth_study_scrng_elig'] ?? '') === '')
                && ($r['baby_shock']                 ?? '') === 'N'
                && ($r['baby_neurological_eligible'] ?? '') === 'Yes',

            'baby_shock' => fn($r) =>
                ($r['baby_shock']                  ?? '') === 'Y'
                && ($r['baby_newborn_surgery']       ?? '') === 'N'
                && ($r['baby_cong_mal_elig']         ?? '') === 'Yes'
                && ($r['baby_skin_disease_elig']     ?? '') === 'Yes'
                && ($r['baby_nicu_weight_elig']      ?? '') === 'Yes'
                && (($r['baby_oth_study_scrng_elig'] ?? '') === 'Yes' || ($r['baby_oth_study_scrng_elig'] ?? '') === '')
                && ($r['baby_neurological_eligible'] ?? '') === 'Yes',

            'baby_neurological_eligible' => fn($r) =>
                ($r['baby_neurological_eligible']  ?? '') === 'No'
                && ($r['baby_shock']                 ?? '') === 'N'
                && ($r['baby_newborn_surgery']       ?? '') === 'N'
                && ($r['baby_cong_mal_elig']         ?? '') === 'Yes'
                && ($r['baby_skin_disease_elig']     ?? '') === 'Yes'
                && ($r['baby_nicu_weight_elig']      ?? '') === 'Yes'
                && (($r['baby_oth_study_scrng_elig'] ?? '') === 'Yes' || ($r['baby_oth_study_scrng_elig'] ?? '') === ''),

            'baby_other_condition' => fn($r) =>
                ($r['baby_other_condition'] ?? '') === 'Y',

            'others_with_wt' => fn($r) =>
                (($r['baby_nicu_weight_elig'] ?? '') === 'No' && ($r['baby_shock']                 ?? '') === 'Y')
                || (($r['baby_nicu_weight_elig'] ?? '') === 'No' && ($r['baby_neurological_eligible'] ?? '') === 'No')
                || (($r['baby_nicu_weight_elig'] ?? '') === 'No' && ($r['baby_newborn_surgery']       ?? '') === 'Y')
                || (($r['baby_nicu_weight_elig'] ?? '') === 'No' && ($r['baby_cong_mal_elig']         ?? '') === 'No')
                || (($r['baby_nicu_weight_elig'] ?? '') === 'No' && ($r['baby_skin_disease_elig']     ?? '') === 'No')
                || (($r['baby_nicu_weight_elig'] ?? '') === 'No' && ($r['baby_oth_study_scrng_elig']  ?? '') === 'No')
                || (($r['baby_oth_study_scrng_elig'] ?? '') === 'No' && ($r['baby_shock']                 ?? '') === 'Y')
                || (($r['baby_oth_study_scrng_elig'] ?? '') === 'No' && ($r['baby_neurological_eligible'] ?? '') === 'No')
                || (($r['baby_oth_study_scrng_elig'] ?? '') === 'No' && ($r['baby_newborn_surgery']       ?? '') === 'Y')
                || (($r['baby_oth_study_scrng_elig'] ?? '') === 'No' && ($r['baby_cong_mal_elig']         ?? '') === 'No')
                || (($r['baby_oth_study_scrng_elig'] ?? '') === 'No' && ($r['baby_skin_disease_elig']     ?? '') === 'No')
                || (($r['baby_shock']            ?? '') === 'Y'  && ($r['baby_neurological_eligible'] ?? '') === 'No')
                || (($r['baby_shock']            ?? '') === 'Y'  && ($r['baby_newborn_surgery']       ?? '') === 'Y')
                || (($r['baby_shock']            ?? '') === 'Y'  && ($r['baby_cong_mal_elig']         ?? '') === 'No')
                || (($r['baby_shock']            ?? '') === 'Y'  && ($r['baby_skin_disease_elig']     ?? '') === 'No')
                || (($r['baby_neurological_eligible'] ?? '') === 'No' && ($r['baby_newborn_surgery']  ?? '') === 'Y')
                || (($r['baby_neurological_eligible'] ?? '') === 'No' && ($r['baby_cong_mal_elig']    ?? '') === 'No')
                || (($r['baby_neurological_eligible'] ?? '') === 'No' && ($r['baby_skin_disease_elig'] ?? '') === 'No')
                || (($r['baby_newborn_surgery'] ?? '') === 'Y' && ($r['baby_cong_mal_elig']     ?? '') === 'No')
                || (($r['baby_newborn_surgery'] ?? '') === 'Y' && ($r['baby_skin_disease_elig'] ?? '') === 'No')
                || (($r['baby_cong_mal_elig']   ?? '') === 'No' && ($r['baby_skin_disease_elig'] ?? '') === 'No'),

            'baby_eligible_enroll' => fn($r) =>
                ($r['baby_eligible_enroll'] ?? '') === 'Yes',
        ];
    }

    /**
     * Display metadata for the screening table.
     * Returns [ metric_key => ['label'=>..., 'section'=>..., 'denominator'=>...] ].
     *
     * denominator key: the metric whose count is used as N for the % column.
     *   baby_prescrn_eligible  → denominator = 'babies_prescreened'   (N = PreScreened)
     *   baby_eligible_enroll   → denominator = 'baby_prescrn_eligible' (N = To be Screened)
     */
    public static function metricMeta(): array
    {
        return [

            /* ── PreScreened section ───────────────────────────────────── */

            'babies_prescreened'     => ['label' => 'Newborns PreScreened',
                                         'section' => 'PreScreened', 'role' => 'primary'],

            'baby_admit_nicu'        => ['label' => 'Not admitted in NICU',
                                         'section' => 'PreScreened',
                                         'subsection' => 'Single Exclusion',
                                         'subsection_total_metric' => 'prescrn_single_excl',
                                         'denominator' => 'babies_prescreened'],

            'birth_weight_no'        => ['label' => 'Birth weight < 700 or > 1800 gms',
                                         'section' => 'PreScreened',
                                         'subsection' => 'Single Exclusion',
                                         'subsection_total_metric' => 'prescrn_single_excl',
                                         'denominator' => 'babies_prescreened'],

            'baby_current_status'    => ['label' => 'Died before Prescreening',
                                         'section' => 'PreScreened',
                                         'subsection' => 'Single Exclusion',
                                         'subsection_total_metric' => 'prescrn_single_excl',
                                         'denominator' => 'babies_prescreened'],

            'baby_admitted_nicu_24hrs' => ['label' => 'NICU admission after 24 hrs',
                                           'section' => 'PreScreened',
                                           'subsection' => 'Single Exclusion',
                                           'subsection_total_metric' => 'prescrn_single_excl',
                                           'denominator' => 'babies_prescreened'],

            'baby_mech_venti'        => ['label' => 'On ventilator',
                                         'section' => 'PreScreened',
                                         'subsection' => 'Single Exclusion',
                                         'subsection_total_metric' => 'prescrn_single_excl',
                                         'denominator' => 'babies_prescreened'],

            'baby_other_study'       => ['label' => 'Other Study',
                                         'section' => 'PreScreened',
                                         'subsection' => 'Single Exclusion',
                                         'subsection_total_metric' => 'prescrn_single_excl',
                                         'denominator' => 'babies_prescreened'],

            'twins'                  => ['label' => 'Eligible Twins/Triplets Excluded',
                                         'section' => 'PreScreened',
                                         'subsection' => 'Single Exclusion',
                                         'subsection_total_metric' => 'prescrn_single_excl',
                                         'denominator' => 'babies_prescreened'],

            'multiple_prscrn_exclusion' => ['label' => 'Multiple PreScreening Exclusion',
                                             'section' => 'PreScreened',
                                             'subsection' => 'Multiple Exclusion',
                                             'subsection_total_metric' => 'prescrn_multiple_excl',
                                             'denominator' => 'babies_prescreened'],

            // FIX: added denominator so exporter renders % (N = PreScreened babies)
            'baby_prescrn_eligible'  => ['label' => 'To be Screened',
                                         'section' => 'Screened',
                                         'role' => 'primary',
                                         'denominator' => 'babies_prescreened'],

            /* ── Screened section ──────────────────────────────────────── */

            'baby_consent_administered' => ['label' => 'Consent not Administered',
                                             'section' => 'Screened',
                                             'subsection' => 'Single Exclusion',
                                             'subsection_total_metric' => 'screened_single_excl',
                                             'denominator' => 'baby_prescrn_eligible'],

            'baby_screen_consent'    => ['label' => 'Consent Refusal',
                                         'section' => 'Screened',
                                         'subsection' => 'Single Exclusion',
                                         'subsection_total_metric' => 'screened_single_excl',
                                         'denominator' => 'baby_prescrn_eligible'],

            'baby_nicu_weight_elig'  => ['label' => 'Admission Weight < 1000 or >= 1500 gms',
                                         'section' => 'Screened',
                                         'subsection' => 'Single Exclusion',
                                         'subsection_total_metric' => 'screened_single_excl',
                                         'denominator' => 'baby_prescrn_eligible'],

            'baby_oth_study_scrng_elig' => ['label' => 'Other Study',
                                             'section' => 'Screened',
                                             'subsection' => 'Single Exclusion',
                                             'subsection_total_metric' => 'screened_single_excl',
                                             'denominator' => 'baby_prescrn_eligible'],

            'baby_skin_disease_elig' => ['label' => 'Generalised Skin Disease',
                                         'section' => 'Screened',
                                         'subsection' => 'Single Exclusion',
                                         'subsection_total_metric' => 'screened_single_excl',
                                         'denominator' => 'baby_prescrn_eligible'],

            'baby_cong_mal_elig'     => ['label' => 'Any Congenital Malformation',
                                         'section' => 'Screened',
                                         'subsection' => 'Single Exclusion',
                                         'subsection_total_metric' => 'screened_single_excl',
                                         'denominator' => 'baby_prescrn_eligible'],

            'baby_newborn_surgery'   => ['label' => 'Undergone or Planned Major Surgery within 28 days of age',
                                         'section' => 'Screened',
                                         'subsection' => 'Single Exclusion',
                                         'subsection_total_metric' => 'screened_single_excl',
                                         'denominator' => 'baby_prescrn_eligible'],

            'baby_shock'             => ['label' => 'Shock',
                                         'section' => 'Screened',
                                         'subsection' => 'Single Exclusion',
                                         'subsection_total_metric' => 'screened_single_excl',
                                         'denominator' => 'baby_prescrn_eligible'],

            'baby_neurological_eligible' => ['label' => 'Severe Neurological Problem',
                                              'section' => 'Screened',
                                              'subsection' => 'Single Exclusion',
                                              'subsection_total_metric' => 'screened_single_excl',
                                              'denominator' => 'baby_prescrn_eligible'],

            'baby_other_condition'   => ['label' => 'Denial of Physician',
                                         'section' => 'Screened',
                                         'subsection' => 'Single Exclusion',
                                         'subsection_total_metric' => 'screened_single_excl',
                                         'denominator' => 'baby_prescrn_eligible'],

            'others_with_wt'         => ['label' => 'Other Screening Exclusion',
                                         'section' => 'Screened',
                                         'subsection' => 'Multiple Exclusion',
                                         'subsection_total_metric' => 'screened_multiple_excl',
                                         'denominator' => 'baby_prescrn_eligible'],

            /* ── To be Enrolled / Enrolled sections ────────────────────── */

            // FIX: added denominator so exporter renders % (N = To be Screened)
            'baby_eligible_enroll'   => ['label' => 'Eligible for Enrollment',
                                         'section' => 'To be Enrolled',
                                         'role' => 'primary',
                                         'denominator' => 'baby_prescrn_eligible'],

            'enr_consent_refused'    => ['label' => 'Enrollment Consent Refusal',
                                         'section' => 'To be Enrolled',
                                         'denominator' => 'baby_eligible_enroll'],

            'enr_enrolled'           => ['label' => 'Study Arm Distribution',
                                         'section' => 'Enrolled',
                                         'role' => 'primary'],

            'enr_intervention'       => ['label' => 'Intervention',
                                         'section' => 'Enrolled',
                                         'denominator' => 'enr_enrolled'],

            'enr_control'            => ['label' => 'Control',
                                         'section' => 'Enrolled',
                                         'denominator' => 'enr_enrolled'],
        ];
    }
    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Resolve ventilator status from the three raw REDCap fields.
     *
     * Rules:
     *   baby_mech_venti_1 = ''  → '' (not yet answered — Not admitted in NICU or form incomplete)
     *   (baby_mech_venti_1 = 'Y' AND baby_venti_confirmation = 'Y')
     *       OR baby_mech_venti_2 = 'Y' → 'Yes' (on ventilator)
     *   otherwise                       → 'No'  (not on ventilator)
     *
     * @param  array  $r  REDCap row
     * @return string  'Yes' | 'No' | ''
     */
    public static function ventiEligiblity(array $r): string
    {
        $v1   = $r['baby_mech_venti_1']       ?? '';
        $conf = $r['baby_venti_confirmation']  ?? '';
        $v2   = $r['baby_mech_venti_2']        ?? '';

        if ($v1 === '') return '';   // unanswered — treat as unknown

        if (($v1 === 'Y' && $conf === 'Y') || $v2 === 'Y') return 'No';

        return 'Yes';
    }

}
