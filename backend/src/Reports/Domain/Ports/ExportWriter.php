<?php

declare(strict_types=1);

namespace Cronos\Reports\Domain\Ports;

interface ExportWriter
{
    /**
     * Writes a spreadsheet to $path and returns the number of data rows written (stopping at $maxRows).
     *
     * @param  list<string>  $headers
     * @param  iterable<list<string|int|float|null>>  $rows
     * @return array{rows: int, truncated: bool}
     */
    public function write(string $path, string $sheet, array $headers, iterable $rows, int $maxRows): array;
}
