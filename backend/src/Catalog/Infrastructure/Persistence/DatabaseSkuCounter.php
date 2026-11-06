<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Persistence;

use Cronos\Catalog\Domain\Ports\SkuCounter;
use Illuminate\Support\Facades\DB;

final class DatabaseSkuCounter implements SkuCounter
{
    public function take(string $prefix): int
    {
        // One statement: the row is created or advanced under its own lock and the number it had is returned.
        $row = DB::selectOne('INSERT INTO sku_counters (prefix, next_number) VALUES (?, 2) ON CONFLICT (prefix) DO UPDATE SET next_number = sku_counters.next_number + 1 RETURNING next_number - 1 AS taken', [$prefix]);

        return (int) $row->taken;
    }
}
