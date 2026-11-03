<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Domain\Ports;

interface SubscriberRepository
{
    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @param array<string, mixed> $row */
    public function create(array $row): string;

    /** @param array<string, mixed> $changes */
    public function update(string $id, array $changes): void;

    public function delete(string $id): void;

    /** @return array{total: int, counts: array{pending: int, confirmed: int, unsubscribed: int}, rows: list<array<string, mixed>>} */
    public function search(?string $status, ?string $term, int $limit, int $offset): array;
}
