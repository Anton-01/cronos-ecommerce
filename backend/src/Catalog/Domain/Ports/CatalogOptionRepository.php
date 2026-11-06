<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Ports;

interface CatalogOptionRepository
{
    /** @return list<array{id: int, catalog: string, code: string, labels: array<string, string>, meta: ?array<string, mixed>, position: int, is_active: bool, in_use: bool}> */
    public function all(string $catalog, bool $includeInactive): array;

    /** @return array{id: int, catalog: string, code: string, labels: array<string, string>, meta: ?array<string, mixed>, position: int, is_active: bool}|null */
    public function find(int $id): ?array;

    /** @return array{id: int, catalog: string, code: string, labels: array<string, string>, meta: ?array<string, mixed>, position: int, is_active: bool}|null */
    public function findByCode(string $catalog, string $code): ?array;

    /** @param array<string, mixed> $row */
    public function create(array $row): int;

    /** @param array<string, mixed> $changes */
    public function update(int $id, array $changes): void;

    public function delete(int $id): void;

    /** Is any product data sheet using this option? */
    public function isInUse(string $catalog, string $code): bool;
}
