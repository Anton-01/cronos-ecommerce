<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Ports;

interface ProductSpecStore
{
    /** @return array{product_id: string, product_type_id: ?string, specs: array<string, mixed>, states: array<string, string>}|null */
    public function find(string $productId): ?array;

    /** @param array<string, mixed> $specs @param array<string, string> $states */
    public function save(string $productId, ?string $typeId, array $specs, array $states, ?string $userId): void;

    /**
     * Products whose data sheet still has required fields pending, with those fields (the "missing data" list).
     *
     * @return list<array{product_id: string, sku: ?string, name: ?string, status: string, type_id: string, missing: list<string>}>
     */
    public function withMissing(int $limit): array;
}
