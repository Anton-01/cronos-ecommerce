<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Persistence;

use Cronos\Inventory\Domain\Ports\ProductValuation;
use Illuminate\Support\Facades\DB;

final class DatabaseProductValuation implements ProductValuation
{
    public function unitValue(string $productId): ?string
    {
        $cost = DB::table('inventory_movements')->where('product_id', $productId)->whereNotNull('unit_cost')->orderByDesc('id')->value('unit_cost');

        return $cost === null ? null : (string) $cost;
    }
}
