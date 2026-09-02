<?php

/**
 * Site Label Map — Emollient Project
 *
 * AUTO-GENERATED on 2026-08-07 09:19:11
 * Command: php tools/generate_label_map.php --project=Emollient --sites=baby_hosp_code
 *
 * Maps hospital site codes to display names used as column headers
 * and row labels across all reports in this project.
 *
 * Covers all fields that hold the same hospital code set:
 *   baby_hosp_code (screening form), enr_hosp_code (enrolment form)
 * The codes are identical across forms so one file covers all.
 *
 * Re-run after any REDCap data dictionary change.
 * Safe to edit manually — the generator will overwrite on next run.
 *
 * Usage in reports.php:
 *   'site_labels_path' => __DIR__ . '/site_labels.php'
 *
 * Sites: 10
 */

return [
    'GSVM' => 'GSVM Medical College',
    'SNMC' => 'SNMC',
    'GTB' => 'GTB Delhi',
    'GMC' => 'GMC Surat',
    'NILO' => 'Niloufer Hospital',
    'SSLH' => 'Sir Sunderlal Hospital',
    'JSS' => 'JSS Hospital',
    'KGMU' => 'KGMU Lucknow',
    'RMLIMS' => 'Dr. Ram Manohar Lohia Institute of Medical Sciences',
    'BRD' => 'BRD Medical College, Gorakhpur',
];
