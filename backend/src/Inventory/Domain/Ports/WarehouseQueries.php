<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Ports;

/** Read models for the warehouse screens. Everything is read-only and already joined with names, so a screen needs one call. */
interface WarehouseQueries
{
    /** @return list<array<string, mixed>> every location with how many products and units it holds (own + below) */
    public function locations(int $warehouseId, bool $includeInactive): array;

    /** @return array<string, mixed>|null */
    public function locationByCode(string $code, ?int $warehouseId): ?array;

    /** @return list<array<string, mixed>> what one location holds */
    public function locationContents(string $locationId): array;

    /**
     * What a scanned code shows: the product whatever its state, where it is, how much, what is promised, pending picks, thresholds and the latest movements.
     *
     * @return array<string, mixed>|null
     */
    public function scan(string $stockCode): ?array;

    /** @return list<array<string, mixed>> */
    public function searchProducts(string $search, int $limit): array;

    /**
     * The traceability trail, newest first.
     *
     * @param  array{productId?: ?string, locationId?: ?string, warehouseId?: ?int, userId?: ?string, type?: ?string, from?: ?string, to?: ?string, before?: ?int}  $filter
     * @return list<array<string, mixed>>
     */
    public function trail(array $filter, int $limit): array;

    /**
     * Adds names, the order number and the shelves that hold the product (pickable first) to lines of the pick list.
     *
     * @param  list<array{order_id: string, product_id: string, warehouse_id: int, pending: string}>  $lines
     * @return list<array<string, mixed>>
     */
    public function describePickLines(array $lines): array;

    /** @return list<array<string, mixed>> */
    public function countSessions(?int $warehouseId, ?string $status, int $limit): array;

    /** @return list<array<string, mixed>> */
    public function countEntries(string $sessionId, ?string $status, int $limit): array;

    /** @return list<array<string, mixed>> */
    public function adjustmentRequests(?string $status, ?int $warehouseId, int $limit): array;

    /** @return list<array<string, mixed>> thresholds with product and place names */
    public function thresholds(?int $warehouseId, int $limit): array;

    /** @param list<string> $ids @return array<string, string> user id => display name */
    public function userNames(array $ids): array;
}
