<?php

declare(strict_types=1);

namespace Cronos\Wishlist\Domain\Ports;

interface WishlistRepository
{
    /** The shopper's default list, created on first use. */
    public function defaultListId(string $userId): string;

    /** @return list<string> product ids, newest first */
    public function productIds(string $listId, int $limit, int $offset): array;

    public function count(string $listId): int;

    public function has(string $listId, string $productId): bool;

    public function add(string $listId, string $productId): void;

    public function remove(string $listId, string $productId): void;

    /** @param list<string> $productIds @return list<string> those that are in the list */
    public function containing(string $listId, array $productIds): array;
}
