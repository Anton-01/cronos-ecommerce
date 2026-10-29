<?php

declare(strict_types=1);

namespace Cronos\Promotions\Domain\Ports;

interface PromotionRepository
{
    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @return list<array<string, mixed>> by priority then newest */
    public function all(): array;

    /** @param array<string, mixed> $data @return string */
    public function create(array $data): string;

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): void;

    public function delete(string $id): void;
}
