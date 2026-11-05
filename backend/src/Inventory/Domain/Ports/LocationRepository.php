<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Ports;

interface LocationRepository
{
    /** @return list<array<string, mixed>> every location of a warehouse, tree order not guaranteed */
    public function all(int $warehouseId): array;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @return array<string, mixed>|null */
    public function findByCode(int $warehouseId, string $code): ?array;

    /** @param array<string, mixed> $row @return string id */
    public function create(array $row): string;

    /** Applies the changes, raises the version and stores the snapshot of the new version, atomically. @param array<string, mixed> $changes */
    public function revise(string $id, array $changes, ?string $userId): void;

    /** @return list<array<string, mixed>> the history of one location, oldest first */
    public function versions(string $id): array;

    /** Ids of the location and everything below it. @return list<string> */
    public function subtreeIds(string $id): array;

    /** Height of the subtree (1 = no children). */
    public function subtreeHeight(string $id): int;

    /** Quantity held in these locations, all products added (a location with any is not emptied). */
    public function stockIn(array $locationIds): string;

    /** Rewrites path and depth of a moved subtree. @param array<string, mixed> $changes */
    public function relocate(string $id, ?string $newParentId, ?string $userId): void;
}
