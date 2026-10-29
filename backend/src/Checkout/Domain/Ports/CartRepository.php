<?php

declare(strict_types=1);

namespace Cronos\Checkout\Domain\Ports;

interface CartRepository
{
    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @return array<string, mixed>|null */
    public function findActiveByTokenHash(string $hash): ?array;

    /** @return array<string, mixed>|null */
    public function findActiveForUser(string $userId): ?array;

    /** @param array<string, mixed> $cart */
    public function insert(array $cart): string;

    /** @param array<string, mixed> $changes */
    public function update(string $id, array $changes): void;

    /** @return list<array{product_id: string, quantity: string, by_roll: bool}> */
    public function items(string $cartId): array;

    public function setItem(string $cartId, string $productId, string $quantity, bool $byRoll): void;

    public function removeItem(string $cartId, string $productId): void;

    public function clearItems(string $cartId): void;

    /** Moves the guest items into the target cart; a product in both keeps the larger quantity. */
    public function mergeInto(string $targetId, string $sourceId): void;

    public function atomically(callable $work): mixed;
}
