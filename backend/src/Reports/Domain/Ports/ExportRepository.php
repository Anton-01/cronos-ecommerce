<?php

declare(strict_types=1);

namespace Cronos\Reports\Domain\Ports;

interface ExportRepository
{
    /** @param array<string, mixed> $job */
    public function insert(array $job): void;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @param array<string, mixed> $changes */
    public function update(string $id, array $changes): void;

    /** A live (queued or running) export of the same person and filters, so a double click does not build the file twice. @return array<string, mixed>|null */
    public function findLive(string $userId, string $type, string $filtersHash): ?array;

    public function countLive(string $userId): int;

    /** @return list<array<string, mixed>> */
    public function forUser(string $userId, int $limit): array;

    /** @return list<array<string, mixed>> ready exports past their expiry */
    public function expired(\DateTimeImmutable $now, int $limit): array;

    /** @return list<array<string, mixed>> queued/running exports nobody is working on any more */
    public function stuck(\DateTimeImmutable $before, int $limit): array;
}
