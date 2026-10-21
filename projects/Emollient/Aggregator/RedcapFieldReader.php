<?php

namespace CEL\Projects\Emollient\Aggregator;

/**
 * RedcapFieldReader — small helpers shared by the Sepsis and Emollient-Stopped aggregators.
 *
 * Works with flat REDCap rows (API export or CSV export, RAW values, not labels).
 */
final class RedcapFieldReader
{
    /**
     * Codes selected in a field, whatever its type:
     *  - dropdown / radio: the column itself holds the code          → [code]
     *  - checkbox: one column per option, field___code = 1/Checked    → [code, code, ...]
     * Checkbox column suffixes are lower-cased by REDCap; they are mapped back to the
     * dictionary's code when $choices is given, otherwise upper-cased.
     */
    public static function codes(array $row, string $field, array $choices = []): array
    {
        $v = trim((string)($row[$field] ?? ''));
        if ($v !== '') return [$v];

        $out = [];
        $prefix = $field . '___';
        foreach ($row as $k => $val) {
            if (!str_starts_with((string)$k, $prefix)) continue;
            if (!self::isChecked($val)) continue;
            $out[] = self::codeForSuffix(substr($k, strlen($prefix)), $choices);
        }
        return $out;
    }

    public static function isChecked(mixed $val): bool
    {
        $v = trim((string)$val);
        return $v === '1' || strcasecmp($v, 'Checked') === 0;
    }

    public static function codeForSuffix(string $suffix, array $choices): string
    {
        foreach (array_keys($choices) as $code) {
            if (strtolower((string)$code) === strtolower($suffix)) return (string)$code;
        }
        return strtoupper($suffix);
    }

    public static function date(mixed $v): ?\DateTimeImmutable
    {
        $v = trim((string)$v);
        if ($v === '') return null;
        try { return new \DateTimeImmutable($v); } catch (\Exception) { return null; }
    }

    public static function inPeriod(\DateTimeInterface $dt, ?\DateTimeInterface $from, ?\DateTimeInterface $to): bool
    {
        if ($from && $dt < \DateTimeImmutable::createFromInterface($from)->setTime(0, 0, 0)) return false;
        if ($to && $dt > \DateTimeImmutable::createFromInterface($to)->setTime(23, 59, 59)) return false;
        return true;
    }

    /** Day number from event names like day7_arm_1 (null if not a day event). */
    public static function dayNo(string $event): ?int
    {
        return preg_match('/^day(\d+)_/i', $event, $m) ? (int)$m[1] : null;
    }

    /**
     * Parse a REDCap data dictionary CSV → [field => [code => label]].
     * Only fields with choices (dropdown, radio, checkbox) are returned.
     */
    public static function loadDictionary(?string $path): array
    {
        if (!$path || !is_file($path)) return [];
        $dict = [];
        foreach (self::readCsv($path) as $r) {
            $field   = $r['Variable / Field Name'] ?? $r['field_name'] ?? null;
            $choices = $r['Choices, Calculations, OR Slider Labels'] ?? $r['select_choices_or_calculations'] ?? '';
            $type    = $r['Field Type'] ?? $r['field_type'] ?? '';
            if (!$field || !in_array($type, ['dropdown', 'radio', 'checkbox'], true)) continue;
            foreach (explode('|', $choices) as $c) {
                $parts = explode(',', $c, 2);
                if (count($parts) === 2) $dict[$field][trim($parts[0])] = trim($parts[1]);
            }
        }
        return $dict;
    }

    public static function label(array $dict, string $field, string $code): string
    {
        return $dict[$field][$code] ?? $code;
    }

    /** Stream a CSV file as associative rows (header row = keys). Handles a UTF-8 BOM. */
    public static function readCsv(string $path): \Generator
    {
        $h = fopen($path, 'r');
        if (!$h) throw new \RuntimeException("Cannot open $path");
        $head = fgetcsv($h, 0, ',', '"', '\\');
        if (!$head) { fclose($h); return; }
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$head[0]);
        while (($line = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
            if ($line === [null]) continue;
            yield array_combine($head, array_pad(array_slice($line, 0, count($head)), count($head), ''));
        }
        fclose($h);
    }
}
