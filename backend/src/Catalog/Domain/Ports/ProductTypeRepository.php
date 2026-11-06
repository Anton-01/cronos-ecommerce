<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Ports;

interface ProductTypeRepository
{
    /** @return list<array<string, mixed>> */
    public function all(bool $includeInactive): array;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    public function codeTaken(string $code, ?string $exceptId = null): bool;

    /** @param array<string, mixed> $row @return string id */
    public function create(array $row): string;

    /** @param array<string, mixed> $changes */
    public function update(string $id, array $changes): void;

    public function delete(string $id): void;

    public function isInUse(string $id): bool;
}
