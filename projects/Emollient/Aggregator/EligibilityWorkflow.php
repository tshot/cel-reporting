<?php

namespace CEL\Projects\Emollient\Aggregator;

/**
 * EligibilityWorkflow
 *
 * Value class — pure data derivation, no I/O, no state.
 *
 * Owns two concerns previously embedded in EligibilityAggregator:
 *
 *   applyDerivedTotals() — computes subtotal keys (prescrn_single_excl,
 *                          screened_single_excl etc.) that are not counted
 *                          directly from rows but are derived post-pass.
 *
 *   workflowCounts()     — builds the CONSORT workflow array consumed by
 *                          EligibilityHtmlExporter::renderConsort().
 *
 * Both methods are static — the class carries no instance state.
 */
final class EligibilityWorkflow
{
    /**
     * Compute derived subtotal keys into $summary in place.
     * Called after the single-pass aggregation loop.
     *
     * @param array $summary  Eligibility summary keyed by [metric][site]
     */
    public static function applyDerivedTotals(array &$summary): void
    {
        $hospitals = [];
        foreach ($summary as $rows)
        {
            if (is_array($rows))
            {
                $hospitals = array_keys($rows);
                break;
            }
        }

        foreach ($hospitals as $hosp)
        {
            // ── Stage-level totals (used in CONSORT) ──────────────────────────
            $summary['prescreen_excluded'][$hosp] =
                ($summary['babies_prescreened'][$hosp]   ?? 0)
              - ($summary['baby_prescrn_eligible'][$hosp] ?? 0);

            $summary['screening_excluded'][$hosp] =
                ($summary['baby_prescrn_eligible'][$hosp] ?? 0)
              - ($summary['baby_eligible_enroll'][$hosp]  ?? 0);

            // ── PreScreened / Single Exclusion total ──────────────────────────
            $summary['prescrn_single_excl'][$hosp] =
                ($summary['baby_admit_nicu'][$hosp]         ?? 0)
              + ($summary['birth_weight_no'][$hosp]          ?? 0)
              + ($summary['baby_current_status'][$hosp]      ?? 0)
              + ($summary['baby_admitted_nicu_24hrs'][$hosp] ?? 0)
              + ($summary['baby_mech_venti'][$hosp]          ?? 0)
              + ($summary['baby_other_study'][$hosp]         ?? 0)
              + ($summary['twins'][$hosp]                    ?? 0);

            // ── PreScreened / Multiple Exclusion total ────────────────────────
            /* $summary['prescrn_multiple_excl'][$hosp] =
                ($summary['prescrn_dead_brtwt_ineligble'][$hosp]  ?? 0)
              + ($summary['baby_admitted_nicu_24hrs_venti'][$hosp] ?? 0);

			*/
			
			$summary['prescrn_multiple_excl'][$hosp] =
                ($summary['multiple_prscrn_exclusion'][$hosp]  ?? 0) ;


            // ── Screened / Single Exclusion total ─────────────────────────────
            $summary['screened_single_excl'][$hosp] =
                ($summary['baby_consent_administered'][$hosp]  ?? 0)
              + ($summary['baby_screen_consent'][$hosp]        ?? 0)
              + ($summary['baby_nicu_weight_elig'][$hosp]      ?? 0)
              + ($summary['baby_oth_study_scrng_elig'][$hosp]     ?? 0)
              + ($summary['baby_skin_disease_elig'][$hosp]     ?? 0)
              + ($summary['baby_cong_mal_elig'][$hosp]         ?? 0)
              + ($summary['baby_newborn_surgery'][$hosp]       ?? 0)
              + ($summary['baby_shock'][$hosp]                 ?? 0)
              + ($summary['baby_neurological_eligible'][$hosp] ?? 0)
              + ($summary['baby_other_condition'][$hosp]       ?? 0);

            // ── Screened / Multiple Exclusion total ───────────────────────────
            $summary['screened_multiple_excl'][$hosp] =
                ($summary['others_with_wt'][$hosp] ?? 0);

            // ── Enrollment derived totals ─────────────────────────────────────
            $eligible       = $summary['baby_eligible_enroll'][$hosp] ?? 0;
            $consentRefused = $summary['enr_consent_refused'][$hosp]  ?? 0;
            // enr_enrolled = Intervention + Control (arm-based total).
            // Derived here as the sum of the two arms so it always equals
            // the Study Arm Distribution total shown in the table.
            // This is correct whether set by the aggregator or not.
            $summary['enr_enrolled'][$hosp] =
                ($summary['enr_intervention'][$hosp] ?? 0)
              + ($summary['enr_control'][$hosp]      ?? 0);
        }
    }

    /**
     * Build the CONSORT workflow array from a completed $summary.
     * Consumed by EligibilityHtmlExporter::renderConsort().
     *
     * @param  array $summary  Eligibility summary (after applyDerivedTotals)
     * @return array           CONSORT counts and reason breakdowns
     */
    public static function workflowCounts(array $summary): array
    {
        $t = fn(string $key) => $summary[$key]['Total'] ?? 0;

        return [
            'prescreened' => $t('babies_prescreened'),
            'screened'    => $t('baby_prescrn_eligible'),
            'eligible'    => $t('baby_eligible_enroll'),
            'pre_excl'    => $t('babies_prescreened') - $t('baby_prescrn_eligible'),
            'scr_excl'    => $t('baby_prescrn_eligible') - $t('baby_eligible_enroll'),
            'pre_excl_reasons' => [
                'Not admitted in NICU'                         => $t('baby_admit_nicu'),
                'Birth weight ineligible'                      => $t('birth_weight_no'),
                'Died before prescreen'                        => $t('baby_current_status'),
                'NICU admission after 24 hrs'                  => $t('baby_admitted_nicu_24hrs'),
                'On ventilator'                                => $t('baby_mech_venti'),
                'Part of other study'                          => $t('baby_other_study'),
                'Multiple Presceening Exclusions'			   => $t('multiple_prscrn_exclusion'),
                'Eligible twins/triplets excluded'             => $t('twins'),
                /* 'Multiple exclusions (death + ineligible wt)'  => $t('prescrn_dead_brtwt_ineligble'),
                'Multiple exclusions (admit >24 hrs + venti)'  => $t('baby_admitted_nicu_24hrs_venti'), */
            ],
            'scr_excl_reasons' => [
                'Consent not administered'                                   => $t('baby_consent_administered'),
                'Consent refused'                                            => $t('baby_screen_consent'),
                'Weight ineligible'                                          => $t('baby_nicu_weight_elig'),
                'Part of other study'                          				 => $t('baby_oth_study_scrng_elig'),
                'Any skin disease'                                           => $t('baby_skin_disease_elig'),
                'Any congenital malformation'                                => $t('baby_cong_mal_elig'),
                'Undergone or planned major surgery within 28 days of age'   => $t('baby_newborn_surgery'),
                'Shock'                                                      => $t('baby_shock'),
                'Severe neurological problem'                                => $t('baby_neurological_eligible'),
                'Denial of physician'                                        => $t('baby_other_condition'),
                'Multiple exclusion combinations'                            => $t('others_with_wt'),
            ],
        ];
    }
}
