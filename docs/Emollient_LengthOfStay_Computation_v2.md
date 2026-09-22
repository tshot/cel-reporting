# Emollient Study — Length of Stay: Computation Logic

_Aggregator version 2 • Document revision 3 • September 2026_


## 1. Summary

Length of Stay (LOS) is computed for every enrolled baby who had a planned discharge, as the number of completed days between hospital admission and the actual discharge. Every other enrolled baby is still counted: each is assigned exactly one outcome that explains why no LOS was computed, so the report can always be reconciled back to the number enrolled.

```
LOS  =  floor( (discharge datetime − admission datetime) / 1 day )
```

This document describes the logic as implemented in projects/Emollient/Aggregator/LengthOfStayAggregator.php, version 2, September 2026. It replaces the earlier version, which fixed LOS at 28 days for any baby still in hospital at Day 28 and did not apply date filtering.


## 2. Data Used

Seventeen REDCap fields across three events. Nothing else is read.

| Event | Field | Used for |
|---|---|---|
| day0_arm_1 | enr_consent_granted | Whether the baby is enrolled (Y) |
| day0_arm_1 | enr_hosp_code | Site |
| day0_arm_1 | enr_study_arm | Study arm |
| day0_arm_1 | enr_baby_dob | Age, to decide whether the regular discharge form is due |
| day0_arm_1 | enr_datetime | Enrolment date, for the date filter |
| day0_arm_1 | baby_eligible_enroll | Which baby on the record is enrolled, when twins or triplets share it. A calculated field: its value is the literal text 'Yes' |
| day0_arm_1 | baby_datetime_admission | Hospital admission — the start of LOS |
| other_forms_arm_1 | sw_datetime | Study withdrawal |
| other_forms_arm_1 | pd_datetime | Protocol deviation |
| discharge_arm_1 | discharge_form_complete | Whether the regular discharge form is complete (2) |
| discharge_arm_1 | dis_in_hosp | Still in hospital at Day 28 (Y) or discharged before (N) |
| discharge_arm_1 | dis_datetime | Discharge datetime, when discharged before Day 28 |
| discharge_arm_1 | dis_discharge_type | Discharge type, when discharged before Day 28 |
| discharge_arm_1 | discharge_after_28_days_of_stay_complete | Whether the after-28-days form is complete (2) |
| discharge_arm_1 | dis_post_28_datetime | Discharge datetime, when still in hospital at Day 28 |
| discharge_arm_1 | dis_post_28_discharge_type | Discharge type, when still in hospital at Day 28 |
| — | record_id | Record identifier |

> **Site and arm come from enrolment:** Not from the discharge form. This matters: a baby who has not yet been discharged has no discharge record, and would otherwise have no site. Taking site and arm from enrolment means every enrolled baby appears in the site and arm tables, discharged or not. A baby with no site recorded is grouped as 'Unknown', and a baby with no study arm as 'Not recorded', rather than either being dropped.


## 3. Who Is Counted

| Rule | Detail |
|---|---|
| Enrolled | enr_consent_granted is Y. (The code also accepts 1, to match the Discharge Form Completion report exactly — see §8.2.) |
| Site filter | If sites are selected, only babies enrolled at those sites. |
| Date filter | If a period is selected, only babies whose enrolment date (enr_datetime) falls within it, both ends inclusive. A baby with no enrolment date is left out when a period is selected, and included when none is. |

> **Why filter on enrolment date:** Every other Emollient report filters on enrolment date, and it gives the audit a clean meaning: the babies enrolled in this period, and what happened to each of them. Filtering on admission or discharge date would answer a different question and would not reconcile with the other reports.


## 4. Assigning Each Baby an Outcome

Each enrolled baby ends in exactly one of eight outcomes:

| Outcome | Meaning |
|---|---|
| Withdrawn | The baby was withdrawn from the study |
| Protocol deviation | A protocol deviation was recorded |
| Still in study | Under 29 days old; the regular discharge form is not yet due |
| Regular discharge form overdue | 29 days old or more, and the regular discharge form is not complete |
| Awaiting post-28 discharge | Still in hospital at Day 28; the after-28-days form is not yet complete |
| Death, LAMA, Abscond, DOPR, Referral | Excluded by discharge type — one column each |
| Data issue | Something needed to reach an outcome is missing or contradictory — see §6 |
| LOS computed | Planned discharge with valid admission and discharge datetimes |


### 4.1 The order of checks

The checks run in a fixed sequence, and the first that settles a baby's outcome ends the checking. Data issues are not a single step: each is caught at the point where a missing or contradictory value stops the next check from being made.

| Step | Check | Outcome if it applies |
|---|---|---|
| 1 | sw_datetime has a value | Withdrawn |
| 2 | pd_datetime has a value | Protocol deviation |
| 3 | Regular discharge form not complete: |  |
| 3a | — date of birth missing | Data issue |
| 3b | — otherwise, age 29 days or more | Regular discharge form overdue; if younger, Still in study |
| 4 | dis_in_hosp blank | Data issue |
| 5 | dis_in_hosp is N: if the after-28-days form is complete or has a discharge datetime | Data issue — otherwise the regular form supplies the discharge; go to step 7 |
| 6 | dis_in_hosp is Y: if the after-28-days form is not complete | Awaiting post-28 discharge — otherwise that form supplies the discharge; go to step 7. Any value other than Y or N is a data issue |
| 7 | Discharge type is TYP_DEA, TYP_LAMA, TYP_ABS, TYP_DOPR or TYP_REF | Death, LAMA, Abscond, DOPR or Referral |
| 8 | Discharge type is anything other than TYP_FP or TYP_OTH | Data issue |
| 9 | More than one baby on the record marked enrolled | Data issue |
| 10 | Admission datetime missing | Data issue |
| 11 | Discharge datetime missing | Data issue |
| 12 | Discharge earlier than admission | Data issue |
| 13 | None of the above | LOS computed |

> **A consequence worth knowing:** A baby whose discharge type is TYP_DEA but whose Day-28 status is blank is counted as a data issue, not a death. Without the Day-28 answer, the report cannot tell whether the regular form or the after-28-days form holds the discharge, so it cannot read the type. Correcting the Day-28 status moves the baby to Death on the next run.


### 4.2 Why this order

- Withdrawal and deviation come first because a withdrawn baby usually has no discharge record. Checked any later, that baby would be reported as still in study indefinitely.
- Withdrawal and deviation are detected by their datetime fields, not by the forms' completion status. A started form means a case exists behind it, whether or not it was finished.
- A discharge form counts only when marked complete. A partially filled form is treated as not done.
- Discharge-type exclusions are checked before the dates. A baby who died is excluded whether or not the dates are complete; counting them as a data issue would send someone chasing dates on a record that was never going to be included. The data issues that come earlier, in steps 3a to 6, are the ones that stop the report finding the discharge type at all.

### 4.3 'Overdue' versus 'awaiting'

The regular discharge form has a known due point: it is filled at Day 28 even for a baby still in hospital, because that is where “still in hospital at Day 28” is recorded. So once a baby is 29 days old, a missing regular form is overdue.

The after-28-days form has no due point. It is filled whenever the baby actually leaves, which may be weeks later. A baby in outcome 5 is therefore not late — simply not yet discharged. This is why outcome 4 says overdue and outcome 5 says awaiting.


### 4.4 How age is measured

Age is the number of whole calendar days from enr_baby_dob to today. A baby born on 1 June is 28 days old on 29 June and 29 days old on 30 June. The regular discharge form falls due at 29 days — that is, once today is later than DOB + 28.


## 5. Computing LOS


### 5.1 Choosing the discharge form

Two forms can record the discharge. Which one is used depends on what the regular discharge form says about Day 28:

| dis_in_hosp | Discharge datetime from | Discharge type from |
|---|---|---|
| N — discharged before Day 28 | dis_datetime | dis_discharge_type |
| Y — still in hospital at Day 28 | dis_post_28_datetime | dis_post_28_discharge_type |

When dis_in_hosp is Y, the regular form's own discharge date and type are ignored entirely.


### 5.2 Discharge types

| Code | Meaning | Treatment |
|---|---|---|
| TYP_FP | Planned discharge | LOS computed |
| TYP_OTH | Other | LOS computed, and counted separately — see §7.1 |
| TYP_DEA | Death | Excluded |
| TYP_LAMA | Left against medical advice | Excluded |
| TYP_ABS | Abscond | Excluded |
| TYP_DOPR | DOPR | Excluded |
| TYP_REF | Referral | Excluded |
| blank or any other value | — | Data issue |


### 5.3 The calculation

LOS is the time between admission and discharge in completed days — the total elapsed seconds divided by 86,400, rounded down. A partial day is not counted. A stay of 3 days and 23 hours is 3 days; a discharge the same day as admission is 0 days.

Admission is taken from baby_datetime_admission on the screening row where baby_eligible_enroll is 'Yes'. This is hospital admission, which can precede study enrolment by a day or more — so LOS measures the whole hospital stay, not the time since enrolment.


### 5.4 Worked examples

Five babies from the per-baby audit file, recomputed by hand:

| Record | Discharge form | Admission | Discharge | Elapsed | LOS |
|---|---|---|---|---|---|
| 313-114 | Regular | 04 Jun 10:20 | 17 Jun 10:30 | 13.007 days | 13 |
| 313-119 | Regular | 06 Jun 14:49 | 17 Jun 15:49 | 11.042 days | 11 |
| 313-110 | After 28 days | 04 Jun 01:51 | 08 Jul 16:31 | 34.611 days | 34 |
| 313-171 | After 28 days | 18 Jun 04:23 | 23 Jul 15:43 | 35.472 days | 35 |
| 313-112 | After 28 days | 04 Jun 00:09 | 18 Jul 09:00 | 44.369 days | 44 |

A sixth baby from the same file, 313-121, had a discharge type of TYP_DEA. Its outcome is Death and its LOS is blank, even though both dates are recorded.


## 6. Data Issues

A baby is counted as a data issue when a value needed for the next check is missing or contradictory. Each reason is caught at a particular step in §4.1, shown below. The specific reason is recorded for each baby, and the report counts babies by reason and by site.

| Reason | Step | Meaning |
|---|---|---|
| Date of birth missing | 3a | Cannot tell whether the regular discharge form is due yet |
| Day-28 status not recorded | 4 | Regular form complete but dis_in_hosp is blank |
| Both discharge forms filled | 5 | The after-28-days form is complete or has a discharge datetime although dis_in_hosp is N — the forms contradict each other |
| Day-28 status unrecognised | 6 | dis_in_hosp holds something other than Y or N |
| Discharge type missing or unrecognised | 8 | Type blank, or a code in neither the included nor the excluded list |
| More than one baby enrolled | 9 | Two or more screening rows on the record are marked 'Yes' |
| Admission datetime missing | 10 | No admission date on the enrolled baby's screening row |
| Discharge datetime missing | 11 | No discharge date on the form that applies |
| Discharge before admission | 12 | The discharge datetime is earlier than admission |

> **Where a missing admission date comes from:** If no screening row on the record is marked 'Yes', the per-baby file adds “no screening row marked enrolled” to the reason, since the fix is different: marking the right twin, not entering a date.


## 7. Statistics and Bands

Everything in this section is computed only over babies whose outcome is LOS computed, per site, per arm and in total.

| Statistic | Definition |
|---|---|
| n | Number of babies with LOS computed |
| Mean | Arithmetic mean, rounded to one decimal place; halves round away from zero |
| Median | The middle value; for an even count, the average of the two middle values. Not rounded, so it can end in .5 |
| SD | Sample standard deviation (dividing by n − 1), rounded to one decimal place. Blank when n is under 2 |
| Min, Max | Shortest and longest stay, in whole days |
| Other | How many of the n had discharge type TYP_OTH |


### 7.1 Why 'Other' is shown

TYP_OTH is included, but it can mean anything. Showing the count lets someone see if a site's LOS figures rest heavily on discharges nobody has categorised — which would be worth looking into.


### 7.2 Distribution bands

| Band | LOS |
|---|---|
| Under 7 days | 0 to 6 |
| 7 to 14 days | 7 to 14 |
| 15 to 27 days | 15 to 27 |
| 28 days or more | 28 and above |


## 8. Checks Built In


### 8.1 Every baby is accounted for

For each site, each arm and the total, the report checks that the number enrolled equals the sum of all eight outcomes. Because each baby is assigned exactly one outcome, this must always hold. The report marks each row, so a failure would be visible immediately rather than hidden in a total.

On the report page, each count in the audit table is also a link. Clicking it lists exactly those babies in the per-patient table, and the counter above that table shows how many are listed — which should equal the number clicked. The same babies can be listed from the per-baby CSV by filtering its outcome column.


### 8.2 Agreement with the Discharge Form Completion report

Outcome 4, Regular discharge form overdue, uses exactly the rule behind the Pending column in the Discharge Form Completion report: enrolled, 29 days or more since birth, regular discharge form not complete, no withdrawal or deviation, and the same enrolment-date filter.

> **Rule:** For every site, Regular discharge form overdue here must equal Pending there. If they ever differ, one of the two reports has a bug. The first time this was checked, it found one: the Discharge Form Completion report's total row was counting Pending twice.

Both checks are run by tools/verify_los.php, which ends with ALL CHECKS PASSED or reports exactly which row failed.


## 9. The Per-Baby Audit File

The CSV download of the Length of Stay Diagnostic report lists every enrolled baby in the cohort exactly once, so its row count equals the total enrolled.

| Column | Content |
|---|---|
| record_id | REDCap record |
| site, site_name | Site code and display name |
| study_arm | Study arm |
| dob | Date of birth |
| enrolled_on | Enrolment date |
| age_days | Age today, in days |
| admission_date | Hospital admission datetime |
| day28_status | dis_in_hosp as recorded: Y, N or blank |
| discharge_source | Regular or After 28 days — which form supplied the discharge |
| discharge_date | Discharge datetime from that form |
| discharge_type | Discharge type code from that form |
| los_days | LOS, blank unless the outcome is LOS computed |
| outcome, outcome_label | The outcome code and its label |
| detail | For a data issue, the specific reason; for TYP_OTH, a note |

> **Reading it with pandas:** The file is standard CSV and starts with a byte-order mark so Excel displays it correctly. In pandas, read it with encoding='utf-8-sig', or the first column name arrives with an invisible character in front of record_id.


## 10. Limitations Worth Knowing

| Limitation | What it means in practice |
|---|---|
| Day-28 status is taken as entered | dis_in_hosp decides which form is used, and it is not checked against the dates. Only one contradiction is caught: the after-28-days form having data when dis_in_hosp is N. A site that ticks Y in error sends the baby to outcome 5 even if a regular discharge date exists. |
| Outcome 5 has no due point | A baby still in hospital at Day 28 whose after-28-days form was never filled — because it was forgotten, not because the baby is still in hospital — stays in Awaiting post-28 discharge indefinitely. Babies who have been there a long time are worth reviewing by age. |
| LOS includes pre-enrolment days | Admission can precede enrolment, so LOS is the full hospital stay. This is intended, but it means LOS is not the same as time on study. |
| Very long stays are not flagged | Stays of over 90 days occur and are plausible for very preterm babies, but a wrong month or year in a date produces the same figure. They are worth checking in the per-baby file. |
| Age uses the server's date | 'Today' is the reporting server's date. A baby whose 29th day falls on the day of the run can move from Still in study to Regular form overdue between a morning run and an evening one. |
| A future date of birth is not flagged | An impossible DOB later than today gives a negative age, so the baby is treated as Still in study. It would be better caught as a data issue, and is a small change if wanted. |
| Completed days only | Rounding down means a stay of 23 hours counts as 0 days. For comparisons across sites this is consistent; for individual babies it understates the stay by up to a day. |

