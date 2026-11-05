<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Ports;

interface LabelStyleStore
{
    /** @return array<string, mixed>|null the latest style ever saved */
    public function current(): ?array;

    /** @param array<string, mixed> $style */
    public function save(array $style, ?string $userId): void;

    /** @return list<array{style: array<string, mixed>, changed_by: ?string, changed_at: string}> */
    public function history(int $limit): array;
}
