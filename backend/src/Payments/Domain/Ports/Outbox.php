<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\Ports;

interface Outbox
{
    /** Writes a message inside the caller's transaction. @param array<string, mixed> $payload */
    public function add(string $type, array $payload): string;

    /** @return list<array{id: string, type: string, payload: array<string, mixed>, attempts: int}> pending messages that are due, oldest first, locked for this worker */
    public function claimDue(int $limit): array;

    public function markDispatched(string $id): void;

    public function markFailed(string $id, string $error, \DateTimeImmutable $retryAt, bool $giveUp): void;
}
