<?php

namespace CEL\Projects\IKMC\Sync;

/**
 * Simple PSR-3-compatible logger for sync operations.
 * Writes to stdout (CLI) or error_log (web context).
 */
class Logger
{
    private bool   $verbose;
    private string $logFile;
    private bool   $isCli;

    public function __construct(
        bool   $verbose = true,
        string $logFile = ''
    ) {
        $this->verbose = $verbose;
        $this->logFile = $logFile;
        $this->isCli   = PHP_SAPI === 'cli';
    }

    public function info(string $msg): void
    {
        $this->write('INFO', $msg);
    }

    public function warning(string $msg): void
    {
        $this->write('WARN', $msg);
    }

    public function error(string $msg): void
    {
        $this->write('ERROR', $msg);
    }

    private function write(string $level, string $msg): void
    {
        if (!$this->verbose && $level === 'INFO') return;

        $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $msg);

        if ($this->logFile !== '') {
            file_put_contents($this->logFile, $line, FILE_APPEND);
        }

        if ($this->isCli) {
            echo $line;
        } else {
            error_log(rtrim($line));
        }
    }
}
