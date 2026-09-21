<?php

namespace CEL\Shared\Domain\Export;

/**
 * CsvExporter
 *
 * Streams any iterable to a CSV file without loading it all into memory.
 *
 * Two modes depending on how the result is structured:
 *
 *  Aggregate result  — the iterable value is an array keyed by site code.
 *                      Each site row has numeric/scalar values.
 *                      A 'Site' column is prepended automatically.
 *                      Non-site keys (period, site_labels, label_map) are skipped.
 *
 *  Transformer result — the iterable yields plain associative arrays
 *                       (one row per record/event). Headers come from the
 *                       first row's keys. No Site prefix is added.
 *
 * Diagnostics
 * -----------
 * The header is taken from the FIRST row, so this class assumes every row
 * shares that row's keys. That holds for transformer output. It does NOT
 * hold for every aggregator: a nested result (sections rather than one row
 * per site) is not tabular at all, and produces a file that is structurally
 * valid but meaningless.
 *
 * Rather than guess at a restructuring, the exporter now COUNTS the mismatches and
 * reports them via getWarnings() and error_log(). If a report logs warnings
 * here, the answer is a dedicated exporter for that report, not a change to
 * this class.
 *
 * NOTE ON READING THESE FILES: values are written with PHP's default
 * backslash escape. Anything reading them with fgetcsv must pass '' as the
 * escape argument, or values containing a backslash come back corrupted.
 * R's read.csv and pandas.read_csv are RFC 4180 and need no special handling.
 */
class CsvExporter implements ExporterInterface
{
    /** Keys injected by ReportFacade that are not data rows */
    private const META_KEYS = ['period', 'site_labels', 'label_map'];

    /** @var array<string,int> message => count */
    private array $warnings = [];

    /** @return array<string,int> */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function export(\Traversable|array $data, ?string $outputPath = null): void
    {
        $filePath = $outputPath ?? 'output.csv';

        $handle = fopen($filePath, 'w');
        if (!$handle)
        {
            throw new \RuntimeException("Unable to open file for writing: {$filePath}");
        }

        $this->warnings = [];

        $headerKeys     = null;
        $headersWritten = false;
        $rowCount       = 0;
        $shortRows      = 0;
        $extraKeys      = 0;
        $nestedCells    = 0;

        foreach ($data as $key => $row)
        {
            // Skip injected meta keys from ReportFacade
            if (in_array($key, self::META_KEYS, true)) continue;

            // Detect mode from first data row:
            // Aggregate: $key is a site code string, $row is an array of scalars
            // Transformer: $key is an int index, $row is an assoc array (a record)
            if (!$headersWritten)
            {
                $headerKeys = array_keys($row);

                if (is_int($key))
                {
                    // Transformer mode — headers from row keys
                    fputcsv($handle, $headerKeys, ',', '"', '\\');
                }
                else
                {
                    // Aggregate mode — prepend Site column
                    fputcsv($handle, array_merge(['Site'], $headerKeys), ',', '"', '\\');
                }
                $headersWritten = true;
            }
            else
            {
                // Diagnostics only — behaviour is unchanged
                $keys = array_keys($row);
                if (count($keys) < count($headerKeys)) { $shortRows++; }
                $diff = array_diff($keys, $headerKeys);
                if ($diff !== []) { $extraKeys += count($diff); }
            }

            if (is_int($key))
            {
                // Transformer mode — write row values directly
                // Flatten any nested arrays to avoid Array-to-string warnings
                $flat = array_map(function ($v) use (&$nestedCells) {
                    if (is_array($v)) { $nestedCells++; return json_encode($v); }
                    return $v;
                }, array_values($row));
                fputcsv($handle, $flat, ',', '"', '\\');
            }
            else
            {
                // Aggregate mode — prepend site code
                // Skip raw data arrays (e.g. from WeeklyMeetingAggregator)
                if ($key === 'raw') continue;
                // Flatten any nested arrays
                $flat = array_map(function ($v) use (&$nestedCells) {
                    if (is_array($v)) { $nestedCells++; return json_encode($v); }
                    return $v;
                }, array_values($row));
                fputcsv($handle, array_merge([$key], $flat), ',', '"', '\\');
            }

            $rowCount++;
        }

        fclose($handle);

        if ($shortRows > 0)
        {
            $this->warnings['rows with fewer keys than the header'] = $shortRows;
        }
        if ($extraKeys > 0)
        {
            $this->warnings['keys absent from the header'] = $extraKeys;
        }
        if ($nestedCells > 0)
        {
            $this->warnings['nested arrays written as JSON'] = $nestedCells;
        }

        if ($this->warnings !== [])
        {
            error_log(sprintf(
                'CsvExporter: %s in %s — this report is not tabular; check whether it needs a dedicated exporter.',
                json_encode($this->warnings),
                $filePath
            ));
        }
    }
}
