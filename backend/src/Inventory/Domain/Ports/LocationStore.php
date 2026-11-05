<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Ports;

/**
 * Storage of the per-location ledger. Writes happen inside `atomically` with the (product, location) row locked; when two rows are involved
 * (a transfer) the caller locks them in a fixed order (ascending location id) so two opposite transfers cannot deadlock.
 */
interface LocationStore
{
    public function atomically(callable $work): mixed;

    /**
     * Creates the row when missing and locks it until the transaction ends. A row that was never written has version 0 and no count date.
     *
     * @return array{quantity: string, version: int}
     */
    public function lockStock(string $productId, string $locationId): array;

    public function saveStock(string $productId, string $locationId, string $quantity, int $version): void;

    public function lastHash(string $productId, string $locationId): ?string;

    /** @param array<string, mixed> $movement a full location_movements row */
    public function append(array $movement): void;

    /** @return array<string, mixed>|null */
    public function findMovement(string $ulid): ?array;

    /** @return array<string, mixed>|null */
    public function findByIdempotencyKey(string $key): ?array;

    /** @return array<string, mixed>|null the movement that compensates $ulid, if any */
    public function compensationOf(string $ulid): ?array;

    /** @return list<array<string, mixed>> the chain of one product+location, oldest first */
    public function chain(string $productId, string $locationId): array;

    /** @return array{stock: array<string, string>, ledger: array<string, string>, locations: array<string, string>, books: array<string, string>, untracked_codes: list<string>} keyed by "product|location" / "product|warehouse" */
    public function reconciliationSnapshot(): array;

    /** Rebuilds location_stock from the movements. @return int rows corrected */
    public function rebuildDerived(): int;
}
