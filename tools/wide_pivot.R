#!/usr/bin/env Rscript
# ---------------------------------------------------------------------------
# wide_pivot.R — build the WideDump in R from the long export.
#
# The PHP wide transformer buffers every record in memory and runs out of
# room on the full project. R does the same pivot comfortably, so this script
# reproduces the engine's output exactly, using files that always export:
#
#   php tools/dump.php --project=Emollient --report=RawDump --output=/tmp/raw.csv
#   php tools/export_field_map.php --project=Emollient --out=/tmp/fields.csv
#   Rscript tools/wide_pivot.R /tmp/raw.csv /tmp/fields.csv /tmp/wide.csv
#
# Column naming matches the engine:
#   non-repeating   {event}_{form}_{field}
#   repeating       {event}_{form}_{instance}_{field}
#
# Options:
#   --template=FILE     force the engine's full column set. FILE is the output
#                       of:  php tools/blueprint_cols.php --report=WideDump
#                            --out=cols.txt
#                       Columns in the template that no baby has are added
#                       empty, and the order matches the engine. Without this,
#                       a field blank for EVERY record simply does not appear,
#                       so headers vary between extracts.
#   --exclude=FILE      de-identification. FILE lists REDCap field names, one
#                       per line (# comments allowed). An entry beginning with
#                       * is a suffix rule: *_lat drops every field ending in
#                       _lat. Matching columns are dropped BEFORE the pivot,
#                       so identifiers never enter the wide file.
#                       Use tools/phi_fields.txt.
#   --events=FILE       event_name,event_order from export_field_map.php
#                       --events. Without it, events fall back to day number
#                       order and anything non-numeric sorts last.
#   --sample=N          only the first N record_ids, for a quick check
# ---------------------------------------------------------------------------

suppressPackageStartupMessages({
  ok <- require(data.table, quietly = TRUE)
})
if (!ok) stop("data.table is required:  install.packages('data.table')")

args  <- commandArgs(trailingOnly = TRUE)
flags <- grep("^--", args, value = TRUE)
files <- setdiff(args, flags)

if (length(files) < 3) {
  cat("Usage: Rscript wide_pivot.R <raw.csv> <fields.csv> <wide.csv>",
      "[--template=cols.txt] [--sample=N]\n")
  quit(status = 1)
}

raw_file    <- files[1]
fields_file <- files[2]
out_file    <- files[3]
template    <- sub("--template=", "", grep("^--template=", flags, value = TRUE))
if (length(template) == 0) template <- NA_character_
exclude     <- sub("--exclude=", "", grep("^--exclude=", flags, value = TRUE))
if (length(exclude) == 0) exclude <- NA_character_
eventsfile  <- sub("--events=", "", grep("^--events=", flags, value = TRUE))
if (length(eventsfile) == 0) eventsfile <- NA_character_
samp        <- as.integer(sub("--sample=", "",
                 grep("^--sample=", flags, value = TRUE)))
if (length(samp) == 0 || is.na(samp)) samp <- 0L

msg <- function(...) cat(sprintf(...), "\n", sep = "")

# ---- 1. read ---------------------------------------------------------------
msg("Reading %s ...", raw_file)
long <- fread(raw_file, colClasses = "character", na.strings = NULL,
              showProgress = FALSE)
msg("  %d rows, %d columns", nrow(long), ncol(long))

meta_cols <- c("record_id", "redcap_event_name",
               "redcap_repeat_instrument", "redcap_repeat_instance")
missing <- setdiff(c("record_id", "redcap_event_name"), names(long))
if (length(missing)) {
  stop("long file is missing: ", paste(missing, collapse = ", "),
       " — use RawDump or RawLongitudinalDump")
}
for (m in meta_cols) if (!m %in% names(long)) long[, (m) := ""]

if (samp > 0) {
  keep <- head(unique(long$record_id), samp)
  long <- long[record_id %in% keep]
  msg("  --sample=%d -> %d rows, %d records", samp, nrow(long), length(keep))
}

# ---- de-identification: drop identifying fields before anything else --------
if (!is.na(exclude)) {
  if (!file.exists(exclude)) stop("exclude list not found: ", exclude)
  phi <- readLines(exclude, warn = FALSE)
  phi <- trimws(phi)
  phi <- phi[nzchar(phi) & !startsWith(phi, "#")]
  msg("De-identification: %d field(s) listed in %s", length(phi), exclude)

  base  <- sub("___.+$", "", names(long))
  exact <- phi[!startsWith(phi, "*")]
  sufx  <- sub("^\\*", "", phi[startsWith(phi, "*")])

  hit <- base %in% exact
  for (sf in sufx) hit <- hit | endsWith(base, sf)

  if (any(hit)) {
    msg("  dropping %d column(s): %s", sum(hit),
        paste(head(names(long)[hit], 8), collapse = ", "))
    long[, (names(long)[hit]) := NULL]
  } else {
    msg("  no listed field present in this file")
  }
  absent <- setdiff(exact, base)
  if (length(absent)) msg("  listed but absent: %d", length(absent))
}

fields <- fread(fields_file, colClasses = "character", showProgress = FALSE)
setnames(fields, tolower(names(fields)))
field2form <- setNames(fields$form_name, fields$field_name)
msg("  field map: %d entries", length(field2form))

# ---- 2. melt to one row per (record, event, instance, field) ----------------
msg("Melting ...")
value_cols <- setdiff(names(long), meta_cols)
m <- melt(long, id.vars = meta_cols, measure.vars = value_cols,
          variable.name = "field", value.name = "value",
          variable.factor = FALSE)
rm(long); invisible(gc())
msg("  %d cells", nrow(m))

# empty cells carry no information and dominate the volume
m <- m[!is.na(value) & value != ""]
msg("  %d non-empty cells", nrow(m))

# ---- 3. resolve the form for each cell -------------------------------------
# repeating rows name the instrument; everything else comes from the dictionary
m[, form := fifelse(redcap_repeat_instrument != "",
                    redcap_repeat_instrument,
                    field2form[field])]

unmapped <- m[is.na(form) | form == ""]
if (nrow(unmapped)) {
  msg("  WARNING: %d cells (%d distinct fields) have no form in the map",
      nrow(unmapped), uniqueN(unmapped$field))
  print(head(unique(unmapped$field), 10))
  m <- m[!is.na(form) & form != ""]
}

# ---- 4. build the engine's column name -------------------------------------
m[, colname := fifelse(
    redcap_repeat_instance != "" & !is.na(redcap_repeat_instance),
    paste0(redcap_event_name, "_", form, "_", redcap_repeat_instance, "_", field),
    paste0(redcap_event_name, "_", form, "_", field))]

dupes <- m[, .N, by = .(record_id, colname)][N > 1]
if (nrow(dupes)) {
  msg("  WARNING: %d record/column pairs appear more than once.", nrow(dupes))
  msg("  The first value is kept. Inspect these before trusting the output:")
  print(head(dupes, 5))
  m <- unique(m, by = c("record_id", "colname"))
}

# ---- 5. pivot --------------------------------------------------------------
msg("Pivoting to one row per record_id ...")
wide <- dcast(m, record_id ~ colname, value.var = "value",
              fill = "", fun.aggregate = function(x) x[1])
rm(m); invisible(gc())
msg("  %d rows, %d columns", nrow(wide), ncol(wide))

# Columns exist only where at least one baby has a value, because empty cells
# were filtered out before the pivot. A field blank for every record therefore
# does not appear at all. Supply --template to force the engine's full set so
# the header is identical between extracts.
if (!is.na(template)) {
  if (!file.exists(template)) stop("template not found: ", template)
  tmpl <- readLines(template, warn = FALSE)
  tmpl <- tmpl[nzchar(tmpl)]
  tmpl <- setdiff(tmpl, "record_id")   # record_id is always placed first
  msg("Template: %s (%d columns)", template, length(tmpl))

  missing_cols <- setdiff(tmpl, names(wide))
  if (length(missing_cols)) {
    wide[, (missing_cols) := ""]
    msg("  added %d template columns no record has a value for", length(missing_cols))
  }

  extra <- setdiff(names(wide), c("record_id", tmpl))
  if (length(extra)) {
    msg("  WARNING: %d column(s) in the data are NOT in the template:", length(extra))
    print(head(extra, 10))
    msg("  They are kept, at the end. A template from a different report or an")
    msg("  older extract is the usual cause.")
  }

  setcolorder(wide, c("record_id", intersect(tmpl, names(wide)), extra))
  msg("  final: %d columns, ordered to match the template", ncol(wide))
} else {
  msg("No --template given. Columns reflect THIS extract only: a field blank")
  msg("  for every record is absent, so headers may differ between extracts.")
}

# ---- 6. order columns: dictionary order, not alphabetical --------------------
# Sort key is event order, then form order, then repeat instance, then the
# field's position in the REDCap data dictionary. Without this the columns come
# out alphabetically within each event, which scatters a form's variables.
if (is.na(template)) {
  cols  <- setdiff(names(wide), "record_id")
  parts <- data.table(col = cols)

  # split the column name back into its parts using the known field names
  known <- fields$field_name[nzchar(fields$field_name)]
  known <- known[order(-nchar(known))]

  base_of <- sub("___.+$", "", parts$col)
  fld <- vapply(base_of, function(b) {
    h <- known[b == known | endsWith(b, paste0("_", known))]
    if (length(h)) h[1] else NA_character_
  }, character(1), USE.NAMES = FALSE)
  parts[, field := fld]

  parts[, ev := sub("^((?:day[0-9]+|[a-z_]+)_arm_[0-9]+)_.*$", "\\1", col)]
  parts[!grepl("_arm_[0-9]+$", ev), ev := ""]

  # The instance number sits immediately before the field name. Derive it by
  # removing the field from the end, then looking for a trailing _<digits>.
  # A regex alone cannot do this: field names contain underscores too.
  stem <- mapply(function(b, f) {
    if (is.na(f)) return(NA_character_)
    if (b == f) return("")
    sub(paste0("_", f, "$"), "", b)
  }, base_of, fld, USE.NAMES = FALSE)
  inst <- suppressWarnings(as.integer(sub("^.*_([0-9]+)$", "\\1", stem)))
  inst[!grepl("_[0-9]+$", stem)] <- NA_integer_
  parts[, instance := fifelse(is.na(inst), 0L, inst)]

  ford <- setNames(as.integer(fields$field_order), fields$field_name)
  mord <- setNames(as.integer(fields$form_order),  fields$field_name)
  parts[, f_ord := fifelse(is.na(field), 1e9, as.numeric(ford[field]))]
  parts[, m_ord := fifelse(is.na(field), 1e9, as.numeric(mord[field]))]

  if (!is.na(eventsfile) && file.exists(eventsfile)) {
    evs <- fread(eventsfile, colClasses = "character", showProgress = FALSE)
    eord <- setNames(as.integer(evs$event_order), evs$event_name)
    parts[, e_ord := fifelse(ev %in% names(eord), as.numeric(eord[ev]), 1e9)]
    msg("Ordering: dictionary order, events from %s", basename(eventsfile))
  } else {
    dn <- suppressWarnings(as.integer(sub("^day([0-9]+)_arm_.*$", "\\1", parts$col)))
    parts[, e_ord := fifelse(is.na(dn), 1e9, as.numeric(dn))]
    msg("Ordering: dictionary order; no --events given, events sorted by day number")
  }

  setorder(parts, e_ord, ev, m_ord, instance, f_ord, col)
  setcolorder(wide, c("record_id", parts$col))

  unres <- sum(is.na(parts$field))
  if (unres) msg("  %d column(s) not matched to a dictionary field — placed last", unres)
}

# ---- 7. write --------------------------------------------------------------
msg("Writing %s ...", out_file)
fwrite(wide, out_file, quote = "auto", na = "")
msg("Done. %d rows, %d columns, %.1f MB",
    nrow(wide), ncol(wide), file.size(out_file) / 1048576)
