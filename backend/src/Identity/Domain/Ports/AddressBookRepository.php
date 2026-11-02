<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\Ports;

interface AddressBookRepository
{
    /** @return list<array<string, mixed>> the person's addresses, defaults first, newest next */
    public function all(string $userId): array;

    /** @return array<string, mixed>|null only if it belongs to that person */
    public function find(string $userId, string $id): ?array;

    public function count(string $userId): int;

    /** @param array<string, mixed> $data */
    public function create(string $userId, array $data): string;

    /** @param array<string, mixed> $data */
    public function update(string $userId, string $id, array $data): void;

    public function delete(string $userId, string $id): void;

    /** Makes it the only default of its kind, atomically. */
    public function makeDefault(string $userId, string $id): void;
}
