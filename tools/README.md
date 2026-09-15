# Tools

## generate_label_map.php

Seeds or refreshes a project's `label_map.php` from live REDCap metadata.

Run when:
- Setting up a new project
- The REDCap data dictionary changes (new options added, labels edited)

Usage:
  php tools/generate_label_map.php \
    --project=Emollient \
    --fields=ses_religion,ses_caste,ses_mthr_edu_qual,ses_head_edu_qual \
    --english-only

Options:
  --dry-run   Preview output without writing to disk
  
  
  
# Generates site_labels.php from REDCap data dictionary
php /var/www/tools/generate_label_map.php \
  --project=Emollient \
  --sites=baby_hosp_code
  
# run dump.php
	php tools/dump.php --project=Emollient --report=WideDump --output=wide.csv
	php tools/dump.php --project=Emollient --report=RawDump  --output=raw.csv
	php tools/dump.php --project=Emollient --report=FlatDump --output=flat.csv
	php tools/dump.php --project=Emollient --report=FullProjectDump --output=full.csv
	php tools/dump.php --project=Emollient --report=RawLongitudinalDump --output=longitudinal.csv
	

# verifying the Emollient Completion (run in browser)
http://reports.local/index.php?project=Emollient&report=EmolliationCoverage&format=html&sites=GSVM,NILO

# for other verifications
http://reports.local/index.php?project=Emollient&report=DailyEmolliationCompletion&format=html&sites=NILO
http://reports.local/index.php?project=Emollient&report=DailyMonitoringCompletion&format=html&sites=GSVM
http://reports.local/index.php?project=Emollient&report=LengthOfStay&format=html&sites=JSS

# for Emolliation flat output
cd /var/www
php tools/dump.php --project=Emollient --report=EmolliationWide --output=/tmp/emol_wide.

cd /var/www
php -d memory_limit=2G tools/dump.php --project=Emollient --report=EmolliationWide --output=/tmp/emol_wide.csv

==== debug
cd /var/www
php tools/diag_wide.php --project=Emollient --report=EmolliationWide > /tmp/diag_output.txt 2>&1
cat /tmp/diag_output.txt


======================================================


can you explain this --- Explicit fields forces REDCap to return per-event longitudinal rows
        // with redcap_event_name populated. Without this, REDCap collapses
// all events into a single flat row per record.

............................

Good question — this deserves a precise explanation because it's not intuitive.

---

## What the REDCap API actually does

When you call the REDCap API with `content=record&format=json`, REDCap has two internal export paths depending on what you send:

**Path 1 — "Give me everything" (no `fields[]` parameter)**

REDCap returns one row per record containing every field from every event merged together. There is no `redcap_event_name` because the concept of "which event" doesn't apply — everything is flattened. This is REDCap's legacy flat export designed for non-longitudinal projects.

```json
[
  {
    "record_id": "307-9",
    "enr_hosp_code": "GSVM",
    "int_emoliate_baby": "Y",   ← but which day? unknown
    ...all 702 fields...
  }
]
```

**Path 2 — "Give me these specific fields" (with `fields[]` parameter)**

REDCap realises it needs to return the data in longitudinal form because specific fields are requested and they may appear on multiple events. It returns one row per record **per event** with `redcap_event_name` populated on each row.

```json
[
  { "record_id": "307-9", "redcap_event_name": "day0_arm_1", "int_emoliate_baby": "Y", ... },
  { "record_id": "307-9", "redcap_event_name": "day3_arm_1", "int_emoliate_baby": "Y", ... },
  { "record_id": "307-9", "redcap_event_name": "day3_arm_1", "redcap_repeat_instrument": "daily_interventionemolliation_form", "redcap_repeat_instance": "2", ... }
]
```

---

## Why this happens

REDCap's flat export predates longitudinal projects. When it was extended to support longitudinal projects, the "return everything" path was kept for backwards compatibility — it still returns one wide row per record. Only when you filter by specific fields does REDCap switch to the longitudinal per-event path.

This is a REDCap API design quirk, not a bug. The documentation doesn't make it obvious — it just says `fields[]` is optional. The consequence is:

| API call | REDCap path | `redcap_event_name` | Rows per record |
|---|---|---|---|
| No `fields[]` | Legacy flat | Missing | 1 |
| `fields[] = [record_id]` | Longitudinal | Populated | One per event |
| `fields[] = [record_id, int_emoliate_baby]` | Longitudinal | Populated | One per event |

---

## What the fix does

By always passing `fields[] = [$primaryKey]` (i.e. `fields[] = [record_id]`) even when no specific fields are requested, we force REDCap into Path 2. The primary key field exists on every event row so it acts as an anchor that tells REDCap "return longitudinal rows". All other fields from the requested forms then come along on those per-event rows with `redcap_event_name` correctly populated.

It's a minimum viable trigger — one field is enough to switch REDCap into longitudinal mode. The actual data content of the response is unchanged; only the structure (one row per event rather than one row per record) changes.



---
# program to fetch specif fields
cd /var/www
php -r "
require 'reporting-engine/vendor/autoload.php';
\$cfg = require 'projects/Emollient/config.php';
\$client = new CEL\Shared\Infrastructure\Redcap\RedcapApiClient(\$cfg['api_url'], \$cfg['token']);
\$meta = \$client->fetchMetadata();
foreach (\$meta as \$f) 
{
    if (str_contains(\$f['field_name'], 'dc') || str_contains(\$f['field_label'] ?? '', 'ollector') || str_contains(\$f['field_label'] ?? '', 'screener')) 
    {
        echo \$f['field_name'] . ' | ' . \$f['form_name'] . ' | ' . \$f['field_label'] . PHP_EOL;
    }
}
" 2>/dev/null | tee /tmp/dc_fields.txt
cat /tmp/dc_fields.txt


# diagnostic for weekly report
cd /var/www
php tools/diag_weekly.php --project=Emollient 2>&1 | tee /tmp/diag_weekly.txt

==========================================================
# Weekly meeting report
http://reports.local/index.php?project=Emollient&report=WeeklyMeeting&format=html

# Filter by date:
http://reports.local/index.php?project=Emollient&report=WeeklyMeeting&format=html&date_from=2026-01-01&date_to=2026-01-31

# Filter to specific sites:
http://reports.local/index.php?project=Emollient&report=WeeklyMeeting&format=html&sites=GSVM,JSS,KGMU
==========================================================
