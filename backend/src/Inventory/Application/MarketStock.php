<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Brick\Math\BigDecimal;
use Cronos\Inventory\Domain\Ports\InventoryQueries;

/** Whether a store can sell a product right now, counting only the warehouses that store sells from. */
final class MarketStock
{
    public const IN_STOCK = 'IN_STOCK';

    public const ON_BACKORDER = 'ON_BACKORDER';

    public const OUT_OF_STOCK = 'OUT_OF_STOCK';

    public function __construct(private readonly InventoryQueries $queries) {}

    /** @return 'IN_STOCK'|'ON_BACKORDER'|'OUT_OF_STOCK' */
    public function status(string $productId, ?string $marketCode): string
    {
        $status = self::OUT_OF_STOCK;
        foreach ($this->queries->marketAvailability([$productId], $marketCode)[$productId] ?? [] as $b) {
            if (! $b['manage_stock'] || BigDecimal::of($b['available'])->isGreaterThanOrEqualTo($b['min_quantity'])) {
                return self::IN_STOCK;
            }
            $b['allow_backorder'] && $status = self::ON_BACKORDER;
        }

        return $status;
    }
}
