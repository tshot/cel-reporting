<?php

namespace CEL\Projects\IKMC\Sync;

/**
 * SyncResult — immutable summary of a single table sync operation.
 */
class SyncResult
{
    public readonly string $srcTable;
    public readonly string $dstTable;
    public readonly float  $startedAt;

    public int   $fetched  = 0;
    public int   $written  = 0;
    public int   $skipped  = 0;
    public int   $errors   = 0;
    public array $errorLog = [];

    public function __construct(string $srcTable, string $dstTable)
    {
        $this->srcTable  = $srcTable;
        $this->dstTable  = $dstTable;
        $this->startedAt = microtime(true);
    }

    public function addFetched(int $n): void { $this->fetched += $n; }
    public function addWritten(): void       { $this->written++; }
    public function addSkipped(): void       { $this->skipped++; }

    public function addError(array $row, string $message): void
    {
        $this->errors++;
        $this->errorLog[] = [
            'table' => $this->dstTable,
            'error' => $message,
            'row'   => $row,
        ];
    }

    public function elapsedSeconds(): float
    {
        return round(microtime(true) - $this->startedAt, 2);
    }

    public function summary(): string
    {
        return sprintf(
            "[%s → %s] fetched=%d written=%d skipped=%d errors=%d (%.2fs)",
            $this->srcTable, $this->dstTable,
            $this->fetched, $this->written, $this->skipped, $this->errors,
            $this->elapsedSeconds()
        );
    }
}
