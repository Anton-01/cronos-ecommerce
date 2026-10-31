<?php

declare(strict_types=1);

namespace Cronos\Inventory\Interfaces\GraphQL;

use Cronos\Inventory\Application\MarketStock;

final class MarketStockStatusField
{
    public function __construct(private readonly MarketStock $stock) {}

    /** @param array<string, mixed> $product @param array<string, mixed> $args */
    public function __invoke(array $product, array $args): string
    {
        return $this->stock->status($product['id'], $args['market']);
    }
}
