<?php

declare(strict_types=1);

namespace Cronos\Pricing\Domain\Ports;

interface TaxStore
{
    /** @return list<array<string, mixed>> */
    public function classes(): array;

    /** @return array<string, mixed>|null */
    public function findClass(int $id): ?array;

    /** @param array<string, mixed> $data @return int */
    public function createClass(array $data): int;

    /** @param array<string, mixed> $data */
    public function updateClass(int $id, array $data): void;

    public function deleteClass(int $id): void;

    public function classInUse(int $id): bool;

    public function classNameTaken(string $name, ?int $exceptId = null): bool;

    /** @return list<array<string, mixed>> */
    public function rates(?int $taxClassId = null, ?int $marketId = null): array;

    /** @return array<string, mixed>|null */
    public function findRate(int $id): ?array;

    /** @param array<string, mixed> $data @return int */
    public function createRate(array $data): int;

    /** @param array<string, mixed> $data */
    public function updateRate(int $id, array $data): void;

    public function deleteRate(int $id): void;
}
