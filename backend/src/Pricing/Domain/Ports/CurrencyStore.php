<?php

declare(strict_types=1);

namespace Cronos\Pricing\Domain\Ports;

interface CurrencyStore
{
    /** @return array{id: int, code: string, name: string, symbol: string, decimals: int, exchange_rate: string, is_default: bool, is_active: bool, rate_updated_at: ?string}|null */
    public function find(int $id): ?array;

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array;

    /** @return list<array<string, mixed>> */
    public function all(): array;

    /** @param array<string, mixed> $data @return int new id */
    public function create(array $data): int;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void;

    public function inUse(int $id): bool;

    public function usedByActiveMarket(int $id): bool;

    /** Stores the rate and appends it to the immutable history. */
    public function setRate(int $id, string $rate, string $source): void;

    /** @return list<array{rate: string, source: string, created_at: string}> newest first */
    public function history(int $id, int $limit = 30): array;
}
