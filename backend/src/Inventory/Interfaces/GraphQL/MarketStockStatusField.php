<?php

declare(strict_types=1);

namespace Cronos\Inventory\Interfaces\GraphQL;

use Brick\Math\BigDecimal;
use Cronos\Inventory\Domain\Ports\InventoryQueries;

final class MarketStockStatusField
{
    public function __construct(private readonly InventoryQueries $queries) {}

    /** @param array<string, mixed> $product @param array<string, mixed> $args @return 'IN_STOCK'|'ON_BACKORDER'|'OUT_OF_STOCK' */
    public function __invoke(array $product, array $args): string
    {
        $status = 'OUT_OF_STOCK';
        foreach ($this->queries->marketAvailability([$product['id']], $args['market'])[$product['id']] ?? [] as $b) {
            if (! $b['manage_stock'] || BigDecimal::of($b['available'])->isGreaterThanOrEqualTo($b['min_quantity'])) {
                return 'IN_STOCK';
            }
            $b['allow_backorder'] && $status = 'ON_BACKORDER';
        }

        return $status;
    }
}
