<?php

namespace CEL\Shared\Domain\Export;

interface ExporterInterface
{
    /**
     * @param iterable $data  Array or Generator
     * @param string|null $outputPath  If null → output to stdout
     */
    public function export(iterable $data, ?string $outputPath = null): void;
}
