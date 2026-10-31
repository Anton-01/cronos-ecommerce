<?php

declare(strict_types=1);

namespace Cronos\Reports\Domain\Ports;

interface ExportSource
{
    /** @return list<string> column titles, in the order of the rows */
    public function headers(string $type): array;

    /**
     * Rows one by one (a cursor, never the whole result in memory).
     *
     * @param  array{from?: ?string, to?: ?string, market?: ?string, status?: ?string}  $filters  from/to are dates (YYYY-MM-DD) in $timezone
     * @return \Generator<int, list<string|int|float|null>>
     */
    public function rows(string $type, array $filters, string $timezone): \Generator;
}
