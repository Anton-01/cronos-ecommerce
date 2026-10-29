<?php

declare(strict_types=1);

namespace Cronos\Pricing\Domain\Ports;

interface MarketStore
{
    /** @return array<string, mixed>|null */
    public function find(int $id): ?array;

    /** @return list<array<string, mixed>> */
    public function all(): array;

    /** @param array<string, mixed> $data @return int new id */
    public function create(array $data): int;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void;

    public function delete(int $id): void;

    public function codeTaken(string $code, ?int $exceptId = null): bool;
}
