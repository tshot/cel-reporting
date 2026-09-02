<?php
namespace CEL\Projects\iKMC\Aggregator;

use CEL\Shared\Domain\Aggregator\AbstractAggregator;

/**
 * ConsortAggregator
 *
 * Computes all counts for the iKMC CONSORT flow diagram.
 *
 * ── Decision Tree (top to bottom) ───────────────────────────────────────────
 *
 *   1. Pre-screened
 *      scr_baby_scrno IS NOT NULL
 *
 *      ├── Not preterm/LBW (Excluded)
 *      │   (scr_birthweight ≥ 2500 AND scr_inf_ga_weeks ≥ 37 AND <99)
 *      │   OR (scr_birthweight = 9999 AND scr_inf_ga_weeks ≥ 37 AND <99)
 *      │   OR (scr_birthweight ≥ 2500 AND <9999 AND scr_inf_ga_weeks = 99)
 *      │
 *      ├── Missing BW and GA (Excluded)
 *      │   scr_birthweight = 9999 AND scr_inf_ga_weeks = 99
 *      │
 *      ├── Died in first 2 hours (Excluded)
 *      │   (scr_birthweight < 2500 OR scr_inf_ga_weeks < 37) AND scr_status_baby != 11
 *      │
 *      └── 2. Screened (Preterm/LBW alive)
 *          (scr_birthweight < 2500 OR scr_inf_ga_weeks < 37) AND scr_status_baby = 11
 *
 *           ├── Inborn:  scr_pob = 11
 *           ├── Outborn: scr_pob != 11
 *           │
 *           ├── BW/GA above cutoff & not sick (Excluded) — see SCREEN_FAILURE_BW_GA_ABOVE_CUTOFF
 *           ├── Outborn not reached within 24hrs (Excluded)
 *           │   (cutoffs met AND scr_pob != 11 AND scr_baby_reach_24hrs != 11 AND scr_beligenr = 12)
 *           ├── Spontaneous breathing failure (Excluded) — scr_sp_brth = 12
 *           ├── Congenital malformation (Excluded)       — scr_cong_malf = 11
 *           ├── In shock / inotropes (Excluded)          — scr_b_inotrf_2hrs = 11
 *           ├── Mechanical ventilation (Excluded)        — scr_b_mech_vent_2hrs = 11
 *           │
 *           └── 3. Eligible for M-SNCU care
 *               scr_beligenr = 11
 *
 *                ├── Inborn:  scr_beligenr=11 AND scr_pob=11
 *                ├── Outborn: scr_beligenr=11 AND scr_pob!=11
 *                │
 *                ├── Refused consent       — scr_beligenr=11 AND scr_mconst=12
 *                ├── Bed shortage          — scr_beligenr=11 AND scr_mconst=13
 *                ├── Transferred (other)   — scr_beligenr=11 AND scr_ext1=13
 *                ├── Transferred (iKMC)    — scr_beligenr=11 AND scr_ext1=14
 *                ├── Discharged            — scr_beligenr=11 AND scr_ext1=15
 *                ├── Died                  — scr_beligenr=11 AND scr_ext1=16
 *                ├── LAMA                  — scr_beligenr=11 AND scr_ext1=17
 *                ├── Not admitted          — scr_beligenr=11 AND scr_ext1=18
 *                │
 *                └── 4. Eligible & Consented
 *                    scr_beligenr=11 AND scr_mconst=11
 *
 *                     ├── Consented but not enrolled (enr_dof IS NULL)
 *                     │
 *                     └── 5. Enrolled
 *                         scr_beligenr=11 AND scr_mconst=11 AND enr_dof IS NOT NULL
 *
 *                          ├── Inborn:  AND scr_pob=11
 *                          ├── Outborn: AND scr_pob!=11
 *                          │
 *                          ├── Still in hospital under follow-up
 *                          │   (enr_dof IS NOT NULL AND dis_dof IS NULL)
 *                          │
 *                          └── 6. Completed outcome assessment
 *                              dis_dof IS NOT NULL
 *
 *                               ├── Discharged to home   — dis_inf_outcome = 11
 *                               ├── LAMA                 — dis_inf_outcome = 12
 *                               ├── Death                — dis_inf_outcome = 13
 *                               └── Transfer non-study   — dis_inf_outcome = 14
 *
 * ── Output ──────────────────────────────────────────────────────────────────
 *
 * [
 *   'period' => ['date_from' => ..., 'date_to' => ...],
 *   'boxes'  => [
 *     'pre_screened'       => ['count' => int],
 *     'excluded_not_lbw'   => ['count' => int],
 *     'excluded_missing'   => ['count' => int],
 *     'excluded_died_2h'   => ['count' => int],
 *     'screened'           => ['count' => int, 'inborn' => int, 'outborn' => int],
 *     'excluded_above_cutoff' => ['count' => int],
 *     'excluded_no_24h'    => ['count' => int],
 *     'excluded_no_spont_breath' => ['count' => int],
 *     'excluded_cong_malf' => ['count' => int],
 *     'excluded_inotropes' => ['count' => int],
 *     'excluded_mech_vent' => ['count' => int],
 *     'eligible'           => ['count' => int, 'inborn' => int, 'outborn' => int],
 *     'refused_consent'    => ['count' => int],
 *     'bed_shortage'       => ['count' => int],
 *     'transferred_other'  => ['count' => int],
 *     'transferred_ikmc'   => ['count' => int],
 *     'discharged_before'  => ['count' => int],
 *     'died_before'        => ['count' => int],
 *     'lama_before'        => ['count' => int],
 *     'not_admitted'       => ['count' => int],
 *     'consented'          => ['count' => int, 'inborn' => int, 'outborn' => int],
 *     'consented_no_enr'   => ['count' => int],
 *     'enrolled'           => ['count' => int, 'inborn' => int, 'outborn' => int],
 *     'still_in_hospital'  => ['count' => int],
 *     'outcome_complete'   => ['count' => int],
 *     'outcome_discharged' => ['count' => int],
 *     'outcome_lama'       => ['count' => int],
 *     'outcome_death'      => ['count' => int],
 *     'outcome_transfer'   => ['count' => int],
 *   ],
 * ]
 */
class ConsortAggregator extends AbstractAggregator
{
    private string $primaryKey;
    private ?\DateTime $from;
    private ?\DateTime $to;

    public function __construct(
        string  $primaryKey = 'recordid',
        ?string $dateFrom   = null,
        ?string $dateTo     = null
    ) {
        $this->primaryKey = $primaryKey;
        $this->from = $dateFrom ? (new \DateTime($dateFrom))->setTime(0, 0, 0)    : null;
        $this->to   = $dateTo   ? (new \DateTime($dateTo))->setTime(23, 59, 59)   : null;
    }

    // =========================================================================
    // Aggregate
    // =========================================================================

    public function aggregate(iterable $records): array
    {
        $boxes = [
            'pre_screened'              => 0,
            'excluded_not_lbw'          => 0,
            'excluded_missing'          => 0,
            'excluded_died_2h'          => 0,
            'screened'                  => 0,
            'screened_inborn'           => 0,
            'screened_outborn'          => 0,
            'excluded_above_cutoff'     => 0,
            'excluded_no_24h'           => 0,
            'excluded_no_spont_breath'  => 0,
            'excluded_cong_malf'        => 0,
            'excluded_inotropes'        => 0,
            'excluded_mech_vent'        => 0,
            'eligible'                  => 0,
            'eligible_inborn'           => 0,
            'eligible_outborn'          => 0,
            'refused_consent'           => 0,
            'bed_shortage'              => 0,
            'transferred_other'         => 0,
            'transferred_ikmc'          => 0,
            'discharged_before'         => 0,
            'died_before'               => 0,
            'lama_before'               => 0,
            'not_admitted'              => 0,
            'consented'                 => 0,
            'consented_inborn'          => 0,
            'consented_outborn'         => 0,
            'consented_no_enr'          => 0,
            'enrolled'                  => 0,
            'enrolled_inborn'           => 0,
            'enrolled_outborn'          => 0,
            'still_in_hospital'         => 0,
            'outcome_complete'          => 0,
            'outcome_discharged'        => 0,
            'outcome_lama'              => 0,
            'outcome_death'             => 0,
            'outcome_transfer'          => 0,
        ];

        foreach ($records as $row) {
            $id = $row[$this->primaryKey] ?? null;
            if (!$id) continue;

            // ── Date filter: scr_dof or scr_baby_scrno entry date ──────────
            // Document does not specify the exact date field — using scr_dof
            // if present. Fall back to no filter (count all).
            if (!$this->inDateRange($row)) continue;

            // ── Extract fields with type coercion ──────────────────────────
            $scrBabyScrno  = trim((string)($row['scr_baby_scrno']  ?? ''));
            $bw            = $this->num($row['scr_birthweight']    ?? '');
            $ga            = $this->num($row['scr_inf_ga_weeks']   ?? '');
            $statusBaby    = $this->intVal($row['scr_status_baby'] ?? '');
            $pob           = $this->intVal($row['scr_pob']         ?? '');
            $bwtCut        = $this->intVal($row['scr_bwtcut']      ?? '');
            $gaCutSncu     = $this->intVal($row['scr_ga_cut_sncu'] ?? '');
            $bwAbvLbw      = $this->intVal($row['scr_bwt_abv_cutoff_lbw'] ?? '');
            $bwAbvLbwSick  = $this->intVal($row['scr_bwt_abv_cutoff_lbw_sick'] ?? '');
            $babyReach24h  = $this->intVal($row['scr_baby_reach_24hrs'] ?? '');
            $beligenr      = $this->intVal($row['scr_beligenr']    ?? '');
            $mconst        = $this->intVal($row['scr_mconst']      ?? '');
            $ext1          = $this->intVal($row['scr_ext1']        ?? '');
            $spBrth        = $this->intVal($row['scr_sp_brth']     ?? '');
            $congMalf      = $this->intVal($row['scr_cong_malf']   ?? '');
            $inotrf2h      = $this->intVal($row['scr_b_inotrf_2hrs'] ?? '');
            $mechVent2h    = $this->intVal($row['scr_b_mech_vent_2hrs'] ?? '');
            $enrDof        = trim((string)($row['enr_dof']         ?? ''));
            $disDof        = trim((string)($row['dis_dof']         ?? ''));
            $disInfOutcome = $this->intVal($row['dis_inf_outcome'] ?? '');

            // ─────────────────────────────────────────────────────────────────
            // Box 1 — Pre-screened
            // ─────────────────────────────────────────────────────────────────
            if ($scrBabyScrno === '') continue;
            $boxes['pre_screened']++;

            // Sub-counts of pre-screened that don't proceed to screening
            $isNotLBW =
                ($bw !== null && $bw >= 2500 && $bw < 9999  && $ga !== null && $ga >= 37 && $ga < 99) ||
                ($bw !== null && $bw == 9999                 && $ga !== null && $ga >= 37 && $ga < 99) ||
                ($bw !== null && $bw >= 2500 && $bw < 9999  && $ga !== null && $ga == 99);

            $isMissing = ($bw == 9999 && $ga == 99);

            $isLBWorPreterm = ($bw !== null && $bw < 2500) || ($ga !== null && $ga < 37);
            $isDied2h       = $isLBWorPreterm && $statusBaby !== 11;

            if ($isNotLBW)    $boxes['excluded_not_lbw']++;
            if ($isMissing)   $boxes['excluded_missing']++;
            if ($isDied2h)    $boxes['excluded_died_2h']++;

            // ─────────────────────────────────────────────────────────────────
            // Box 2 — Screened (preterm/LBW alive)
            // ─────────────────────────────────────────────────────────────────
            $isScreened = $isLBWorPreterm && $statusBaby === 11;
            if (!$isScreened) continue;

            $boxes['screened']++;
            $isInborn = ($pob === 11);
            if ($isInborn)   $boxes['screened_inborn']++;
            else             $boxes['screened_outborn']++;

            // Exclusions at the screened level
            if ($spBrth      === 12) $boxes['excluded_no_spont_breath']++;
            if ($congMalf    === 11) $boxes['excluded_cong_malf']++;
            if ($inotrf2h    === 11) $boxes['excluded_inotropes']++;
            if ($mechVent2h  === 11) $boxes['excluded_mech_vent']++;

            // BW/GA above cutoff & not sick — document gives a complex multi-line condition
            $aboveCutoffNotSick =
                ($bwtCut !== 11 && $gaCutSncu !== 11
                    && $bw !== null && $bw < 2500
                    && $bwAbvLbw === 11
                    && $bwAbvLbwSick === 12
                    && $beligenr === 12);
            if ($aboveCutoffNotSick) $boxes['excluded_above_cutoff']++;

            // Outborn babies not reached iKMC-IF within 24hrs
            $no24h =
                (($bwtCut === 11 || $gaCutSncu === 11
                    || ($bwAbvLbw === 11 && $bwAbvLbwSick === 11))
                 && $pob !== 11
                 && $babyReach24h !== 11
                 && $beligenr === 12);
            if ($no24h) $boxes['excluded_no_24h']++;

            // ─────────────────────────────────────────────────────────────────
            // Box 3 — Eligible for M-SNCU care
            // ─────────────────────────────────────────────────────────────────
            if ($beligenr !== 11) continue;

            $boxes['eligible']++;
            if ($isInborn)  $boxes['eligible_inborn']++;
            else            $boxes['eligible_outborn']++;

            // Pre-consent dropouts
            if ($mconst === 12)  $boxes['refused_consent']++;
            if ($mconst === 13)  $boxes['bed_shortage']++;
            if ($ext1   === 13)  $boxes['transferred_other']++;
            if ($ext1   === 14)  $boxes['transferred_ikmc']++;
            if ($ext1   === 15)  $boxes['discharged_before']++;
            if ($ext1   === 16)  $boxes['died_before']++;
            if ($ext1   === 17)  $boxes['lama_before']++;
            if ($ext1   === 18)  $boxes['not_admitted']++;

            // ─────────────────────────────────────────────────────────────────
            // Box 4 — Eligible AND Consented
            // ─────────────────────────────────────────────────────────────────
            if ($mconst !== 11) continue;

            $boxes['consented']++;
            if ($isInborn)  $boxes['consented_inborn']++;
            else            $boxes['consented_outborn']++;

            $hasEnrDof = $enrDof !== '' && $enrDof !== '0000-00-00';
            if (!$hasEnrDof) {
                $boxes['consented_no_enr']++;
                continue;  // not enrolled, stop here
            }

            // ─────────────────────────────────────────────────────────────────
            // Box 5 — Enrolled
            // ─────────────────────────────────────────────────────────────────
            $boxes['enrolled']++;
            if ($isInborn)  $boxes['enrolled_inborn']++;
            else            $boxes['enrolled_outborn']++;

            $hasDisDof = $disDof !== '' && $disDof !== '0000-00-00';
            if (!$hasDisDof) {
                $boxes['still_in_hospital']++;
                continue;
            }

            // ─────────────────────────────────────────────────────────────────
            // Box 6 — Outcome complete
            // ─────────────────────────────────────────────────────────────────
            $boxes['outcome_complete']++;

            switch ($disInfOutcome) {
                case 11: $boxes['outcome_discharged']++; break;
                case 12: $boxes['outcome_lama']++;       break;
                case 13: $boxes['outcome_death']++;      break;
                case 14: $boxes['outcome_transfer']++;   break;
            }
        }

        return [
            'period' => [
                'date_from' => $this->from?->format('Y-m-d'),
                'date_to'   => $this->to?->format('Y-m-d'),
            ],
            'boxes'  => $boxes,
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function num(mixed $v): ?float
    {
        $s = trim((string)$v);
        if ($s === '' || !is_numeric($s)) return null;
        return (float)$s;
    }

    private function intVal(mixed $v): ?int
    {
        $s = trim((string)$v);
        if ($s === '' || !is_numeric($s)) return null;
        return (int)$s;
    }

    private function inDateRange(array $row): bool
    {
        if (!$this->from && !$this->to) return true;

        // Use scr_dof as the primary date field — assumption flagged in doc
        $raw = trim((string)($row['scr_dof'] ?? ''));
        if ($raw === '' || $raw === '0000-00-00') {
            return true;  // include records without a date rather than drop silently
        }

        try { $dt = new \DateTime($raw); }
        catch (\Exception) { return true; }

        if ($this->from && $dt < $this->from) return false;
        if ($this->to   && $dt > $this->to)   return false;
        return true;
    }
}
