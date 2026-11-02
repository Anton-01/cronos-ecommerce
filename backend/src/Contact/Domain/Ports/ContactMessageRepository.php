<?php

declare(strict_types=1);

namespace Cronos\Contact\Domain\Ports;

interface ContactMessageRepository
{
    /** @param array<string, mixed> $row */
    public function create(array $row): string;

    public function countFromIp(string $ip, int $minutes): int;

    public function countFromEmail(string $email, int $minutes): int;

    public function sameMessageRecently(string $email, string $ip, string $message, int $minutes): bool;

    /** @return array{total: int, rows: list<array<string, mixed>>} */
    public function search(?string $status, ?string $term, int $limit, int $offset): array;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    public function setStatus(string $id, string $status, ?string $userId): void;
}
