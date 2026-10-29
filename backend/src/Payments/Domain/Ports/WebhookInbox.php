<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\Ports;

interface WebhookInbox
{
    /**
     * Stores a notification once per (provider, event id).
     *
     * @param  array<string, mixed>  $event
     * @return array{id: string, created: bool}
     */
    public function store(array $event): array;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** Claims an event for processing (row lock + status). Returns null when someone else holds it or it is already done. @return array<string, mixed>|null */
    public function claim(string $id): ?array;

    /** @param array<string, mixed> $changes */
    public function update(string $id, array $changes): void;

    /** @return list<string> ids of events whose retry time has come */
    public function due(\DateTimeImmutable $now, int $limit): array;

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    public function search(?string $status, ?string $provider, int $limit, int $offset): array;

    /** @return list<string> ids of failed or dead events (for bulk reprocess) */
    public function failedIds(int $limit): array;
}
