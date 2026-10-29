<?php

declare(strict_types=1);

namespace Cronos\Notifications\Domain\Ports;

interface NotificationLog
{
    /**
     * @param  array<string, mixed>  $row
     * @return string|null the new id, or null when this (template, dedupe_key) was already recorded
     */
    public function record(array $row): ?string;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @param array<string, mixed> $changes */
    public function update(string $id, array $changes): void;

    /** @return list<array<string, mixed>> queued/failed rows old enough to retry */
    public function stale(\DateTimeImmutable $before, int $limit): array;

    /** @return array{total: int, rows: list<array<string, mixed>>} */
    public function search(?string $status, ?string $term, int $limit, int $offset): array;
}
