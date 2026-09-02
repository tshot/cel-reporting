<?php

namespace CEL\Shared\Domain\Export;

/**
 * CsvExporter
 *
 * Streams any iterable to a CSV file without loading it all into memory.
 *
 * Two modes depending on data shape:
 *
 *  Aggregate result  — the iterable value is an array keyed by site code.
 *                      Each site row has numeric/scalar values.
 *                      A 'Site' column is prepended automatically.
 *                      Non-site keys (period, site_labels, label_map) are skipped.
 *
 *  Transformer result — the iterable yields plain associative arrays
 *                       (one row per record/event). Headers come from the
 *                       first row's keys. No Site prefix is added.
 */
class CsvExporter implements ExporterInterface
{
    /** Keys injected by ReportFacade that are not data rows */
    private const META_KEYS = ['period', 'site_labels', 'label_map'];

    public function export(\Traversable|array $data, ?string $outputPath = null): void
    {
        $filePath = $outputPath ?? 'output.csv';

        $handle = fopen($filePath, 'w');
        if (!$handle)
        {
            throw new \RuntimeException("Unable to open file for writing: {$filePath}");
        }

        $headersWritten = false;
        $rowCount       = 0;

        foreach ($data as $key => $row)
        {
            // Skip injected meta keys from ReportFacade
            if (in_array($key, self::META_KEYS, true)) continue;

            // Detect mode from first data row:
            // Aggregate: $key is a site code string, $row is an array of scalars
            // Transformer: $key is an int index, $row is an assoc array (a record)
            if (!$headersWritten)
            {
                if (is_int($key))
                {
                    // Transformer mode — headers from row keys
                    fputcsv($handle, array_keys($row), ',', '"', '\\');
                }
                else
                {
                    // Aggregate mode — prepend Site column
                    fputcsv($handle, array_merge(['Site'], array_keys($row)), ',', '"', '\\');
                }
                $headersWritten = true;
            }

            if (is_int($key))
            {
                // Transformer mode — write row values directly
                // Flatten any nested arrays to avoid Array-to-string warnings
                $flat = array_map(fn($v) => is_array($v) ? json_encode($v) : $v, array_values($row));
                fputcsv($handle, $flat, ',', '"', '\\');
            }
            else
            {
                // Aggregate mode — prepend site code
                // Skip raw data arrays (e.g. from WeeklyMeetingAggregator)
                if ($key === 'raw') continue;
                // Flatten any nested arrays
                $flat = array_map(fn($v) => is_array($v) ? json_encode($v) : $v, array_values($row));
                fputcsv($handle, array_merge([$key], $flat), ',', '"', '\\');
            }

            $rowCount++;
        }

        fclose($handle);
    }
}
