<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Ports;

interface WarehouseRepository
{
    /** @return array<string, mixed>|null */
    public function find(int $id): ?array;

    /** @return list<array<string, mixed>> */
    public function all(): array;

    /**
     * Active warehouses in the order a reservation should try them: the market's own first, then the default, then the rest.
     *
     * @return list<array<string, mixed>>
     */
    public function candidatesFor(?string $marketCode): array;

    /** @param array<string, mixed> $data @return int */
    public function create(array $data): int;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void;

    public function delete(int $id): void;

    public function codeTaken(string $code, ?int $exceptId = null): bool;

    public function hasStock(int $id): bool;

    public function marketExists(string $code): bool;
}
