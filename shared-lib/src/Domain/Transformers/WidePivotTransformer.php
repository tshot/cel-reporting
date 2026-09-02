<?php

namespace CEL\Shared\Domain\Transformers;

use InvalidArgumentException;

class WidePivotTransformer implements TransformerInterface
{
    private array $columns;
    private array $fieldToForm;
    private string $primaryKey;

    // If set, only output columns whose name contains at least one of
    // these substrings (record_id always included).
    // e.g. ['int_emoliate_baby', 'int_no_emol_reason', '_complete']
    private array $selectFields;

    // If set, only yield rows where at least one column whose name
    // contains any of these strings is non-null and non-empty.
    // e.g. ['_complete'] keeps only babies with at least one form filled.
    private array $filterNonempty;

    // When true, rows that are repeat-form instances
    // (redcap_repeat_instrument set) are skipped entirely. Used by the
    // no-repeat wide dump, where only one row per subject from non-repeating
    // forms is wanted and the blueprint contains no instance columns.
    private bool $skipRepeats;

    public function __construct(
        array $columns,
        array $fieldToForm,
        string $primaryKey,
        array $filterNonempty = [],
        array $selectFields   = [],
        bool  $skipRepeats    = false
    ) {
        if (empty($columns)) 
        {
            throw new InvalidArgumentException(
                "WidePivotTransformer requires non-empty columns."
            );
        }

        if (empty($fieldToForm)) 
        {
            throw new InvalidArgumentException(
                "WidePivotTransformer requires fieldToForm mapping."
            );
        }
        
        if (empty($primaryKey)) 
        {
			throw new \InvalidArgumentException(
				"WidePivotTransformer requires primaryKey."
			);
		}

        $this->columns        = $columns;
        $this->fieldToForm    = $fieldToForm;
        $this->primaryKey     = $primaryKey;
        $this->filterNonempty = $filterNonempty;
        $this->selectFields   = $selectFields;
        $this->skipRepeats    = $skipRepeats;
    }

    public function transform(iterable $records): iterable
    {
        $currentRecordId = null;
        $currentRow = [];

        foreach ($records as $row) 
        {
/* echo "Primary key expected: " . $this->primaryKey . PHP_EOL;
print_r(array_keys($row));
exit;
*/
            $recordId = $row[$this->primaryKey] ?? null;
            
            if (!$recordId) continue;

            // No-repeat wide dump: ignore repeat-form instance rows entirely.
            // Their data belongs to repeating instruments, which this dump
            // deliberately excludes (and for which no columns exist).
            if ($this->skipRepeats
                && !empty($row['redcap_repeat_instrument']))
            {
                continue;
            }

            if ($currentRecordId !== null && $recordId !== $currentRecordId) 
            {
                if ($this->passesFilter($currentRow))
                    yield $currentRow;
                $currentRow = [];
            }

            if ($recordId !== $currentRecordId) 
            {
                $currentRecordId = $recordId;
                // Build the row with all columns, then narrow to selected fields
                $allCols = empty($this->selectFields)
                    ? $this->columns
                    : array_filter(
                        $this->columns,
                        fn($col) => $col === $this->primaryKey
                            || $this->colMatchesSelect($col)
                      );
                $currentRow = array_fill_keys($allCols, null);
                $currentRow[$this->primaryKey] = $recordId;
            }

            $event = $row['redcap_event_name'] ?? null;
            $repeatForm = $row['redcap_repeat_instrument'] ?? null;
            $instance = $row['redcap_repeat_instance'] ?? null;

            foreach ($row as $field => $value) 
            {

                if (in_array($field, [
                    $this->primaryKey,
                    'redcap_event_name',
                    'redcap_repeat_instrument',
                    'redcap_repeat_instance'
                ])) continue;

                if ($repeatForm && $instance) 
                {

                    $column = "{$event}_{$repeatForm}_{$instance}_{$field}";

                } 
                else 
                {

                    $form = $this->fieldToForm[$field] ?? null;
                    if (!$form) continue;

                    $column = "{$event}_{$form}_{$field}";
                }

                if (array_key_exists($column, $currentRow)) 
                {
                    $currentRow[$column] = $value;
                }
            }
        }

        if (!empty($currentRow) && $this->passesFilter($currentRow))
        {
            yield $currentRow;
        }
    }
    /**
     * Returns true if the row should be yielded.
     * When filterNonempty is empty, all rows pass.
     * When set, at least one column whose name contains any of the
     * filter strings must have a non-null, non-empty value.
     */
    private function colMatchesSelect(string $col): bool
    {
        foreach ($this->selectFields as $needle) {
            if (str_contains($col, $needle)) return true;
        }
        return false;
    }

    private function passesFilter(array $row): bool
    {
        if (empty($this->filterNonempty)) return true;

        foreach ($row as $col => $value) {
            if ($value === null || $value === '') continue;
            foreach ($this->filterNonempty as $needle) {
                if (str_contains($col, $needle)) return true;
            }
        }
        return false;
    }
}
