<?php

declare(strict_types=1);

namespace Cronos\Checkout\Domain\Ports;

interface CheckoutRepository
{
    public function atomically(callable $work): mixed;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @return array<string, mixed>|null row lock: use inside atomically() */
    public function lock(string $id): ?array;

    /** @return array<string, mixed>|null */
    public function findByKey(string $key): ?array;

    /** @param array<string, mixed> $checkout */
    public function insert(array $checkout): void;

    /** @param array<string, mixed> $changes */
    public function update(string $id, array $changes): void;

    /** Appends {at, code, detail} to the notes. */
    public function addNote(string $id, string $code, string $detail): void;

    /** @return list<array<string, mixed>> */
    public function openForCart(string $cartId): array;

    public function hasPaid(string $userId): bool;

    /** @return list<array<string, mixed>> open checkouts whose time ran out */
    public function dueForExpiry(\DateTimeImmutable $now, int $limit): array;

    /** @return list<array<string, mixed>> */
    public function forUser(string $userId, int $limit): array;

    /** @return list<array<string, mixed>> */
    public function search(?string $status, ?string $term, int $limit, int $offset): array;
}
