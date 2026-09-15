# Wide dumps — running them, checking them, explaining them

How to produce a one-row-per-baby export of the Emollient REDCap project,
prove it is correct, and hand it to an analyst who has never used REDCap.

Everything runs from the repo root (`/var/www/reports`).

---

## 1. Which report to run

| Report | Forms | Repeating instances | Typical size |
|---|---|---|---|
| `WideDump` | all | expanded | 14,588 cols |
| `WideDumpNoRepeat` | all | not expanded | fewer |
| `FullProjectDump` | 16 core | expanded | — |
| `FullProjectDumpNoRepeat` | 16 core | not expanded | 9,083 cols |
| `RawDump` | all | long format, one row per event | — |
| `RawLongitudinalDump` | 16 core | long format | — |
| `FlatDump` | all | long, composite key | — |

The `Wide*` and `FullProject*` reports give one row per `record_id`.
The `Raw*` and `Flat*` reports stay long — one row per record/event/instance.

For the full list as your `reports.php` actually defines it:

```bash
php tools/diag_wide.php --project=Emollient --report=NoSuchReport
```

That makes no REDCap call and prints every defined report name.

---

## 2. Look before you export

Column counts and the repeat map, without writing a large file:

```bash
php tools/blueprint_cols.php --project=Emollient --report=WideDump --out=/tmp/cols.txt
```

Prints the repeat map (event / form -> max instance) and the total column
count, and writes every column name to `/tmp/cols.txt`.

Useful greps once you have it:

```bash
# every event present in the output
sed -E 's/(_arm_[0-9]+).*/\1/' /tmp/cols.txt | sort -u

# how many columns a form contributes
grep -c 'daily_interventionemolliation_form' /tmp/cols.txt

# the instance numbering for one event
grep 'day1_arm_1_daily_interventionemolliation_form' /tmp/cols.txt | head
```

---

## 3. Export

```bash
php -d memory_limit=8G tools/dump.php \
    --project=Emollient --report=WideDump --output=/tmp/wide.csv
```

Wide mode buffers the whole dataset, so give it room. The `-d` flag works
because `dump.php` only ever RAISES the limit, never lowers it.

Date and site filtering:

```bash
php tools/dump.php --project=Emollient --report=WideDump \
    --date-from=2026-01-01 --date-to=2026-03-31 --output=/tmp/q1.csv
```

---

## 4. Column naming

Non-repeating form:

```
day0_arm_1_registration_reg_dc_id
└─event──┘ └──form────┘ └─field─┘
```

Repeating form — the instance number sits between form and field:

```
day1_arm_1_daily_interventionemolliation_form_3_int_datetime
└─event──┘ └──────────form──────────────────┘ │ └──field──┘
                                         instance
```

Checkbox fields end in `___CODE`, one column per option, each 0 or 1:

```
day1_arm_1_daily_interventionemolliation_form_1_int_sae_reasons___NND
```

Instance caps follow the protocol, not the data: day 0 allows 2 emolliation
records, days 1–28 allow 3. So `day0_..._form_3_...` does not exist, and the
header is stable between exports.

---

## 5. Verify it

### 5a. Shape

```bash
php tools/csvcheck.php /tmp/wide.csv
```

Expect `rows per id = 1.00`, `ragged rows = 0`, and a distinct-id count equal
to the row count.

### 5b. Contents, against the long-format source

The real check. Export the same data both ways and compare cell by cell:

```bash
php tools/dump.php --project=Emollient --report=RawDump --output=/tmp/raw.csv
php tools/verify_wide.php /tmp/wide.csv /tmp/raw.csv
```

It walks every non-empty value in the long file, works out which wide column
should hold it, and compares. Output:

```
non-empty cells checked : N
matched exactly         : N (100.0000%)
no matching wide column : 0
value mismatch          : 0
PASS
```

Anything other than 0/0 prints examples with record id, column, and both
values. For a quick pass use `--sample=200` to check 200 babies only.

### 5c. Arithmetic sanity

Columns for a repeating form should divide evenly by the number of instance
slots. For `daily_interventionemolliation_form`: 3,870 columns over 86 slots
(2 on day 0 + 3 × 28 days) = 45 fields exactly. A non-integer means
something is being dropped or duplicated.

---

## 6. Metadata for the analyst

```bash
php tools/make_codebook.php --project=Emollient --report=WideDump --out=/tmp/emol
```

Produces three files:

| File | Contents |
|---|---|
| `emol_codebook.csv` | one row per wide column: event, form, instance, field, question label, type, answer options, validation, branching logic |
| `emol_labels.csv` | ONE line — question labels in wide-column order |
| `emol_choices.csv` | one row per coded answer: field, code, meaning |

### The label row

`emol_labels.csv` is a single CSV line aligned to the dump's columns, so it
can become a second header row:

```bash
head -1 /tmp/wide.csv  > /tmp/wide_2row.csv   # machine names
cat /tmp/emol_labels.csv >> /tmp/wide_2row.csv # human questions
tail -n +2 /tmp/wide.csv >> /tmp/wide_2row.csv # data
```

**Do this for reading, not for analysis.** A two-row header breaks
`read.csv`, `pandas.read_csv` and every other loader, because row 2 is text
and forces every column to character. Keep `wide.csv` machine-readable and
give the analyst the codebook alongside it. If they want labels in R:

```r
d   <- read.csv("wide.csv", check.names = FALSE)
cb  <- read.csv("emol_codebook.csv")
lbl <- setNames(cb$question_label, cb$column_name)
attr(d, "labels") <- lbl[names(d)]
```

---

## 7. Explaining coded variables

REDCap stores the **code**, not the answer. A dropdown showing
"Yes / No / Not investigated" is stored as `Y`, `N`, `NI`. The export
therefore contains codes, and without the codebook they are unreadable.

`emol_choices.csv` is the lookup:

| field_name | code | meaning |
|---|---|---|
| `int_emoliate_baby` | Y | Yes |
| `int_emoliate_baby` | N | No |
| `int_emoliate_baby` | NI | Not investigated |

Decoding in R:

```r
ch <- read.csv("emol_choices.csv")
decode <- function(x, field) {
  m <- ch[ch$field_name == field, ]
  setNames(m$meaning, m$code)[as.character(x)]
}
d$emol_day1 <- decode(d$day1_arm_1_daily_interventionemolliation_form_1_int_emoliate_baby,
                      "int_emoliate_baby")
```

Three things to tell any analyst new to REDCap:

1. **Blank is not zero and not No.** A blank means the question was never
   answered — often because branching logic hid it. The `branching_logic`
   column in the codebook says when a field was shown at all.
2. **`NI` is its own value.** It means not investigated. Never fold it into
   `N`; that overstates the negative.
3. **Checkbox fields are one column per option**, each 0 or 1, and all of
   them are 0 when the question was not reached — indistinguishable from
   "none selected" unless you check the branching condition.

---

## 8. Running form totals and combinations

Counting across repeating instances means summing the instance columns.

**Emolliation sessions on day 1**, counting only those where it happened:

```r
cols <- grep("^day1_arm_1_daily_interventionemolliation_form_[0-9]+_int_emoliate_baby$",
             names(d), value = TRUE)
d$emol_sessions_day1 <- rowSums(d[cols] == "Y", na.rm = TRUE)
```

**Total sessions across the whole stay:**

```r
cols <- grep("^day[0-9]+_arm_1_daily_interventionemolliation_form_[0-9]+_int_emoliate_baby$",
             names(d), value = TRUE)
d$emol_sessions_total <- rowSums(d[cols] == "Y", na.rm = TRUE)
```

**Total oil used** — sum a numeric field over every instance:

```r
cols <- grep("_daily_interventionemolliation_form_[0-9]+_int_oil_qty$",
             names(d), value = TRUE)
d$oil_total <- rowSums(sapply(d[cols], as.numeric), na.rm = TRUE)
```

**Days with at least one session** (a day counts once, however many
instances):

```r
days <- sprintf("day%d_arm_1", 0:28)
d$days_emolliated <- rowSums(sapply(days, function(ev) {
  cols <- grep(paste0("^", ev, "_daily_interventionemolliation_form_[0-9]+_int_emoliate_baby$"),
               names(d), value = TRUE)
  if (!length(cols)) return(rep(0, nrow(d)))
  as.integer(rowSums(d[cols] == "Y", na.rm = TRUE) > 0)
}))
```

**Adherence** — sessions done over sessions possible, where day 0 allows 2
and days 1–28 allow 3:

```r
d$sessions_possible <- 2 + 3 * 28          # adjust for actual length of stay
d$adherence <- d$emol_sessions_total / d$sessions_possible
```

The pattern throughout: build the column name with a regex on
`{event}_{form}_{instance}_{field}`, then `rowSums`. Use `^` and `$` anchors
— an unanchored pattern will also match `int_emoliate_baby_reason` and
similar fields.

Sanity-check any derived total against the long format, which needs no
reshaping:

```r
long <- read.csv("raw.csv")
sum(long$int_emoliate_baby == "Y", na.rm = TRUE)   # should equal sum(d$emol_sessions_total)
```

---

## 9. Practical limits

- 14,588 columns exceeds what Excel will comfortably open. Use R or pandas.
- `WideDump` is roughly 160 MB; `FullProjectDumpNoRepeat` about 102 MB.
- Instance columns are sparse — most babies have no third session on most
  days. That is inherent to wide format, not a defect.
- Read the CSV with RFC 4180 semantics. In PHP that means passing `''` as
  `fgetcsv`'s escape argument; R and pandas are correct by default.
