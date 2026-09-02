<?php

/**
 * Per-Site Enrolment Targets & Start Dates — Emollient Project
 *
 * Per site:
 *   monthly          — MONTHLY enrolment target (babies/month).
 *   first_collection — date the site began collecting data (yyyy-mm-dd).
 *                      Cumulative targets are measured from THIS date, so a
 *                      site is only judged over the time it has been active.
 *   site_start       — administrative site-start date (yyyy-mm-dd), shown for
 *                      reference only; not used in any calculation.
 *
 * The Monthly Dashboard scales targets two ways:
 *   period target     = monthly × months_in_selected_period
 *   cumulative target = monthly × months from first_collection → period end
 *
 * Keyed by hospital SITE CODE (same codes as site_labels.php).
 * Source: MonthlyEmolientTarget (Sheet2). Edit when protocol targets change.
 */

return [
    'GSVM'   => ['monthly' => 15, 'first_collection' => '2025-09-25', 'site_start' => '2025-09-25'],  // GSVM, Kanpur
    'SSLH'   => ['monthly' => 15, 'first_collection' => '2025-09-26', 'site_start' => '2025-09-25'],  // IMS, BHU (Varanasi)
    'SNMC'   => ['monthly' => 15, 'first_collection' => '2025-09-26', 'site_start' => '2025-09-26'],  // SNMC, Agra
    'NILO'   => ['monthly' => 70, 'first_collection' => '2025-10-13', 'site_start' => '2025-10-13'],  // Niloufer, Hyderabad
    'JSS'    => ['monthly' => 12, 'first_collection' => '2026-02-15', 'site_start' => '2026-02-02'],  // JSS, Mysore
    'KGMU'   => ['monthly' => 20, 'first_collection' => '2026-02-25', 'site_start' => '2026-02-22'],  // KGMU, Lucknow
    'RMLIMS' => ['monthly' => 15, 'first_collection' => '2026-03-19', 'site_start' => '2026-03-16'],  // RML, Lucknow
    'BRD'    => ['monthly' => 15, 'first_collection' => '2026-05-20', 'site_start' => '2026-05-19'],  // BRD, Gorakhpur
    'GTB'    => ['monthly' => 25, 'first_collection' => '2026-08-03', 'site_start' => '2026-07-16'],  // GTB, Delhi
];
