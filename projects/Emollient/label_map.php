<?php

/**
 * REDCap Option Label Map — Emollient Project
 *
 * AUTO-GENERATED on 2026-03-15 07:52:10
 * Command: php tools/generate_label_map.php --project=Emollient --fields=ses_religion,ses_caste,ses_mthr_edu_qual,ses_head_edu_qual
 *
 * Maps raw REDCap option codes to display labels for categorical
 * variables rendered inside report tables (e.g. Page 3 Demographics).
 *
 * Re-run after any REDCap data dictionary change.
 * Safe to edit manually — the generator will overwrite on next run.
 *
 * Usage in reports.php:
 *   'label_map_path' => __DIR__ . '/label_map.php'
 *
 * Fields: 4  |  Total options: 27
 */

return [

    // -- 8 - What is your religion? [आपका धर्म क्या है?] (ses_religion) -- 9 options 
    'ses_religion' => [
        'REL_HIN' => 'Hindu',
        'REL_MUS' => 'Muslim',
        'REL_BUD' => 'Buddhist',
        'REL_SIK' => 'Sikh',
        'REL_CHR' => 'Christian',
        'REL_PAR' => 'Parsi',
        'REL_JAI' => 'Jain',
        'REL_NON' => 'No religion',
        'REL_OTH' => 'Other, please specify',
    ],

    // -- 9 - What is your caste category? [आपकी जाति श्रेणी क्या है?] (ses_caste) -- 4 options 
    'ses_caste' => [
        'CAST_GEN' => 'General',
        'CAST_OBC' => 'OBC',
        'CAST_SC' => 'SC',
        'CAST_ST' => 'ST',
    ],

    // -- 12 - What is the highest educational qualification of the mother?
    'ses_mthr_edu_qual' => [
        'MOMQ_PROF' => 'Profession or Honours',
        'MOMQ_GRAD' => 'Graduate or Postgraduate',
        'MOMQ_INT' => 'Intermediate',
        'MOMQ_HSC' => 'High School',
        'MOMQ_MSC' => 'Middle School',
        'MOMQ_PSC' => 'Primary School',
        'MOMQ_ILL' => 'Illiterate',
    ],

    // -- 11 - What is the highest educational qualification of the Head of the Household?
    'ses_head_edu_qual' => [
        'HHQ_PROF' => 'Profession or Honours',
        'HHQ_GRAD' => 'Graduate or Postgraduate',
        'HHQ_INT' => 'Intermediate',
        'HHQ_HSC' => 'High School',
        'HHQ_MSC' => 'Middle School',
        'HHQ_PSC' => 'Primary School',
        'HHQ_ILL' => 'Illiterate',
    ],

];
