<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Ports;

interface ProductStockInfo
{
    /** @return array{id: string, type: string, sku: ?string, name: ?string, manage_stock: bool, allow_backorder: bool, low_stock_threshold: ?string}|null */
    public function find(string $productId): ?array;
}
