<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Ports;

/** Read side for the back-office screens. */
interface InventoryQueries
{
    /**
     * Stock-tracking products with their totals and a per-warehouse breakdown.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function stock(?string $search, ?int $warehouseId, bool $lowOnly, int $limit, int $offset): array;

    /**
     * Products whose available stock is at or below their low-stock threshold (optionally just one product).
     *
     * @return list<array<string, mixed>>
     */
    public function lowStock(?string $productId = null): array;

    /**
     * Kardex rows, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function movements(?string $productId, ?int $warehouseId, ?string $type, ?string $before, int $limit): array;

    /** @return list<array<string, mixed>> */
    public function reservations(?string $productId, int $limit): array;
}
