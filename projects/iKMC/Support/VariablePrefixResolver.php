<?php
namespace CEL\Projects\iKMC\Support;

/**
 * VariablePrefixResolver
 *
 * Maps iKMC field names to their source tables using prefix rules.
 *
 *   scr_*  → iKMCv2_EligibilityRegistration
 *   enr_*  → iKMCv2_MotherBabyRegistration
 *   dmf_*  → iKMCv2_DailyCareTracking
 *   dis_*  → iKMCv2_Discharge
 *
 * New prefix mappings can be added at runtime via addMapping().
 *
 * The resolver also groups a list of fields by source table — used by
 * IkmcMysqlClient to build optimal JOIN queries (only join tables actually
 * referenced by the report).
 */
class VariablePrefixResolver
{
    private array $prefixMap;
    private string $primaryKey;
    private string $softDeleteColumn;
    private int    $softDeleteActive;

    public function __construct(array $config)
    {
        $this->prefixMap        = $config['prefix_table_map']  ?? [];
        $this->primaryKey       = $config['primary_key']        ?? 'recordid';
        $this->softDeleteColumn = $config['soft_delete_column'] ?? 'deleted';
        $this->softDeleteActive = (int)($config['soft_delete_active'] ?? 0);
    }

    /**
     * Return the source table name for a single field.
     * Throws if no prefix matches.
     */
    public function tableFor(string $field): string
    {
        // Allow alias fields that don't follow prefix rules (e.g. recordid itself)
        if ($field === $this->primaryKey) {
            // Use whichever table is canonical — convention: scr_ table
            return $this->prefixMap['scr_'] ?? throw new \InvalidArgumentException(
                "Cannot resolve table for primary key '{$field}' — no scr_ mapping."
            );
        }

        foreach ($this->prefixMap as $prefix => $table) {
            if (str_starts_with($field, $prefix)) {
                return $table;
            }
        }

        throw new \InvalidArgumentException(
            "No table mapping for field '{$field}'. Known prefixes: "
            . implode(', ', array_keys($this->prefixMap))
        );
    }

    /**
     * Group a list of fields by their source table.
     *
     * @return array<string, string[]>  table => [field, field, ...]
     */
    public function groupByTable(array $fields): array
    {
        $groups = [];
        foreach ($fields as $f) {
            try {
                $table = $this->tableFor($f);
            } catch (\InvalidArgumentException) {
                continue;  // ignore unknown — caller can decide
            }
            $groups[$table][] = $f;
        }

        // De-duplicate within each table
        foreach ($groups as $t => $fs) {
            $groups[$t] = array_values(array_unique($fs));
        }

        return $groups;
    }

    /**
     * Add a new prefix → table mapping at runtime.
     * Useful if new tables are added in future iKMC versions.
     */
    public function addMapping(string $prefix, string $table): void
    {
        $this->prefixMap[$prefix] = $table;
    }

    public function getPrimaryKey(): string         { return $this->primaryKey; }
    public function getSoftDeleteColumn(): string   { return $this->softDeleteColumn; }
    public function getSoftDeleteActive(): int      { return $this->softDeleteActive; }
    public function getPrefixMap(): array           { return $this->prefixMap; }
}
