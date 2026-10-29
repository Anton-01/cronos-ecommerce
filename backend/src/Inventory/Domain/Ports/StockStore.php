<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Ports;

/**
 * Everything the ledger needs from storage. Writes happen inside `atomically`, with the (product, warehouse) level row locked,
 * so two simultaneous sales of the last roll cannot both succeed.
 */
interface StockStore
{
    /** Runs the work in one transaction (nesting joins the outer one) and returns its result. */
    public function atomically(callable $work): mixed;

    /**
     * Creates the level row when missing and locks it until the transaction ends.
     *
     * @return array{on_hand: string, reserved: string}
     */
    public function lockLevel(string $productId, int $warehouseId): array;

    public function saveLevel(string $productId, int $warehouseId, string $onHand, string $reserved): void;

    public function lastHash(string $productId, int $warehouseId): ?string;

    /** @param array<string, mixed> $movement a full Kardex row */
    public function append(array $movement): void;

    /** Sets products.stock_quantity to the available stock of its active warehouses (never below zero) and returns it. */
    public function refreshProductStock(string $productId): string;

    /** @return array<string, mixed>|null */
    public function findMovement(string $ulid): ?array;

    /** @return array<string, mixed>|null the movement that compensates $ulid, if any */
    public function compensationOf(string $ulid): ?array;

    /** @return array{on_hand: string, reserved: string, available: string}|null */
    public function level(string $productId, int $warehouseId): ?array;

    /** @return array{ledger: array<string, string>, levels: array<string, array{on_hand: string, reserved: string}>, reservations: array<string, string>, products: array<string, string>, expected_products: array<string, string>} keyed by "product|warehouse" or product id */
    public function reconciliationSnapshot(): array;

    /** @return list<array<string, mixed>> the chain of one product+warehouse, oldest first */
    public function chain(string $productId, int $warehouseId): array;

    /** Rebuilds stock_levels and products.stock_quantity from the Kardex and the active reservations. @return int rows corrected */
    public function rebuildDerived(): int;
}
