<?php

declare(strict_types=1);

namespace Cronos\Pricing\Application;

use Cronos\Pricing\Domain\Exceptions\InvalidPricing;
use Cronos\Pricing\Domain\Exceptions\NotFound;
use Cronos\Pricing\Domain\Ports\CurrencyStore;
use Cronos\Pricing\Domain\Ports\ProductPriceStore;
use Cronos\Pricing\Domain\ValueObjects\PriceRow;
use DateTimeImmutable;

final class ProductPriceManager
{
    public function __construct(private readonly ProductPriceStore $prices, private readonly CurrencyStore $currencies) {}

    /** @param array{price?: ?string, roll_price?: ?string, compare_at_price?: ?string, sale_price?: ?string, sale_starts_at?: ?string, sale_ends_at?: ?string} $data */
    public function set(string $productId, int $currencyId, array $data): void
    {
        $this->prices->productExists($productId) || throw new NotFound('The product');
        $this->currencies->find($currencyId) ?? throw new NotFound('The currency');

        // The value object is the validator: negative amounts, sale >= price, inverted windows.
        new PriceRow(
            $this->blank($data['price'] ?? null), $this->blank($data['roll_price'] ?? null), $this->blank($data['compare_at_price'] ?? null), $this->blank($data['sale_price'] ?? null),
            $this->date($data['sale_starts_at'] ?? null), $this->date($data['sale_ends_at'] ?? null),
        );

        $this->prices->upsert($productId, $currencyId, [
            'price' => $this->blank($data['price'] ?? null), 'roll_price' => $this->blank($data['roll_price'] ?? null),
            'compare_at_price' => $this->blank($data['compare_at_price'] ?? null), 'sale_price' => $this->blank($data['sale_price'] ?? null),
            'sale_starts_at' => $this->date($data['sale_starts_at'] ?? null)?->format('Y-m-d H:i:sP'), 'sale_ends_at' => $this->date($data['sale_ends_at'] ?? null)?->format('Y-m-d H:i:sP'),
        ]);
    }

    public function clear(string $productId, int $currencyId): void
    {
        $this->prices->delete($productId, $currencyId);
    }

    private function blank(?string $v): ?string
    {
        return $v === null || trim($v) === '' ? null : trim($v);
    }

    private function date(?string $v): ?DateTimeImmutable
    {
        try {
            return $this->blank($v) === null ? null : new DateTimeImmutable($v);
        } catch (\Exception) {
            throw new InvalidPricing("[{$v}] is not a valid date.", 'INVALID_DATE');
        }
    }
}
