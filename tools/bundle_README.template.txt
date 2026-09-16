EMOLLIENT STUDY — DATA EXPORT
=============================
Extract date: 15 September 2026
Contact: Amit Tandon, CEL — amit.tandon@celworld.org

READ THIS FIRST
  Analyst_Guide_v5.docx   Section 1 is a five-minute primer on REDCap data.
                          Section 9 covers Stata specifically.

THE DATA — two copies of the same values, pick one
  wide.csv                Full column names. For R or Python.
  emol_stata.csv          Short variable names. For Stata.

STATA
  emol_import.do          Run this first. Imports emol_stata.csv, applies
                          the question text as variable labels, saves .dta.
                          Keep it in the same folder as emol_stata.csv.
  emol_crosswalk.csv      Short name <-> full name. You need this, because
                          the guide's examples use the full names.

  Requires Stata/SE or Stata/MP. The file has 10,890 variables and
  Stata/BE stops at 2,048.

METADATA — applies to both copies
  emol_codebook.csv       One row per column: question label, form, event,
                          repeat instance, field type, answer options,
                          validation rule, and the branching logic that
                          controls when the question was shown.
  emol_choices.csv        Code to meaning. REDCap stores Y / N / NI, not
                          "Yes" / "No" / "Not investigated".

STRUCTURE IN ONE LINE
  One row per baby. Column names read as
  {event}_{form}_{instance}_{field}, e.g.
  day1_arm_1_daily_interventionemolliation_form_3_int_datetime
  = day 1, emolliation form, third session that day, date and time.

THREE THINGS THAT CAUSE WRONG ANSWERS
  1. Blank is not zero and not "No". It usually means the question was
     never shown — see branching_logic in the codebook.
  2. NI means "not investigated". It is its own value. Folding it into N
     overstates the negative.
  3. Checkbox questions are one column per option, each 0 or 1. All-zero
     is indistinguishable from never-asked without checking the branching.

DE-IDENTIFIED
  Names, addresses and all contact numbers have been removed — the columns
  do not exist. record_id is a study identifier, not a personal one.
  nss_hosp_code is the finest geographic detail available.
