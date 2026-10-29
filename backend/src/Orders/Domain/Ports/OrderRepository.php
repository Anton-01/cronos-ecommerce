<?php

declare(strict_types=1);

namespace Cronos\Orders\Domain\Ports;

interface OrderRepository
{
    public function atomically(callable $work): mixed;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @return array<string, mixed>|null row lock: use inside atomically() */
    public function lock(string $id): ?array;

    /** @return array<string, mixed>|null */
    public function findByNumber(string $number): ?array;

    /** @return array<string, mixed>|null */
    public function findByCheckout(string $checkoutId): ?array;

    public function nextNumber(): string;

    /**
     * @param  array<string, mixed>  $order
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, array<string, mixed>>  $addresses  type => address
     * @param  list<array<string, mixed>>  $discounts
     */
    public function insert(array $order, array $items, array $addresses, array $discounts): void;

    /** @param array<string, mixed> $changes */
    public function update(string $id, array $changes): void;

    /** @param array<string, mixed> $entry */
    public function addHistory(string $orderId, array $entry): void;

    /** @return list<array<string, mixed>> */
    public function items(string $orderId): array;

    /** @return array<string, array<string, mixed>> */
    public function addresses(string $orderId): array;

    /** @return list<array<string, mixed>> */
    public function discounts(string $orderId): array;

    /** @return list<array<string, mixed>> oldest first */
    public function histories(string $orderId): array;

    /** @return list<array<string, mixed>> */
    public function forUser(string $userId, int $limit, int $offset): array;

    public function countForUser(string $userId): int;

    /** @return array{total: int, rows: list<array<string, mixed>>} */
    public function search(?string $status, ?string $paymentStatus, ?string $term, int $limit, int $offset): array;
}
