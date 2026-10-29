<?php

declare(strict_types=1);

namespace Cronos\Pricing\Domain\Ports;

interface ProductPriceStore
{
    /** @return list<array<string, mixed>> price rows of one product, with their currency code */
    public function forProduct(string $productId): array;

    /** @param array<string, mixed> $data */
    public function upsert(string $productId, int $currencyId, array $data): void;

    public function delete(string $productId, int $currencyId): void;

    public function productExists(string $productId): bool;

    /**
     * Base prices (default currency) and tax class: the product's own, else its parent's, else its category's.
     *
     * @param  list<string>  $productIds
     * @return array<string, array{price: ?string, roll_price: ?string, tax_class_id: ?int}>
     */
    public function basePrices(array $productIds): array;

    /**
     * @param  list<string>  $productIds
     * @return array<string, array<string, mixed>> product id => price row for that currency
     */
    public function rowsFor(array $productIds, int $currencyId): array;
}
