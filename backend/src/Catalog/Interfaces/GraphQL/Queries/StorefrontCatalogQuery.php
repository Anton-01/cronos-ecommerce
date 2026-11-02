<?php

declare(strict_types=1);

namespace Cronos\Catalog\Interfaces\GraphQL\Queries;

use Cronos\Catalog\Application\CatalogReader;
use Cronos\Pricing\Domain\Ports\PricingCatalog;

final class StorefrontCatalogQuery
{
    public function __construct(private readonly CatalogReader $reader, private readonly PricingCatalog $pricing) {}

    /** @param array<string, mixed> $args */
    public function categoryTree(mixed $root, array $args): array
    {
        return $this->reader->categoryTree($args['locale'] ?? null);
    }

    /** @param array<string, mixed> $args */
    public function category(mixed $root, array $args): ?array
    {
        return $this->reader->category($args['locale'] ?? null, $args['slug']);
    }

    /** @param array<string, mixed> $args */
    public function product(mixed $root, array $args): ?array
    {
        return $this->reader->product($args['locale'] ?? null, $args['slug']);
    }

    /** @param array<string, mixed> $args */
    public function preview(mixed $root, array $args): array
    {
        return $this->reader->preview($args['locale'] ?? null, $args['token']);
    }

    /** @param array<string, mixed> $args */
    public function products(mixed $root, array $args): array
    {
        return $this->reader->products($args['locale'] ?? null, $this->filter($args), $args['sort'] ?? 'NEWEST', (int) ($args['first'] ?? 24), $args['after'] ?? null);
    }

    /** @param array<string, mixed> $args */
    public function facets(mixed $root, array $args): array
    {
        $facets = $this->reader->facets($args['locale'] ?? null, $this->filter($args));
        $rate = $this->rate($args['market'] ?? null);

        // The range is stored in the base currency; the shopper thinks in the store's own.
        return [
            'total' => $facets['total'], 'attributes' => $facets['attributes'],
            'price' => [
                'min' => $facets['price']['min'] === null ? null : (string) (int) floor((float) $facets['price']['min'] * $rate['rate']),
                'max' => $facets['price']['max'] === null ? null : (string) (int) ceil((float) $facets['price']['max'] * $rate['rate']),
                'currency' => $rate['currency'],
            ],
        ];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function filter(array $args): array
    {
        $rate = $this->rate($args['market'] ?? null)['rate'];

        return array_filter([
            'category' => $args['category'] ?? null,
            'tag' => $args['tag'] ?? null,
            'search' => $args['search'] ?? null,
            'price_min' => $this->price($args['priceMin'] ?? null, 1 / $rate),
            'price_max' => $this->price($args['priceMax'] ?? null, 1 / $rate),
            'attributes' => array_map(fn (array $a) => ['code' => $a['code'], 'values' => array_values($a['values'])], $args['attributes'] ?? []),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Exchange rate between the base currency and the store's (units of the store's currency per one of the base). Without a store, 1.
     * Products with a price of their own for a store are still filtered by their base price: the filter is a guide, the price shown is exact.
     *
     * @return array{rate: float, currency: string}
     */
    private function rate(?string $market): array
    {
        $snapshot = $this->pricing->snapshot();
        $default = collect($snapshot['currencies'])->firstWhere('is_default', true);
        $m = $market === null ? null : collect($snapshot['markets'])->firstWhere('code', strtoupper($market));
        $currency = $m === null ? $default : collect($snapshot['currencies'])->firstWhere('id', $m['currency_id']);
        $rate = (float) ($currency['exchange_rate'] ?? 1);

        return ['rate' => $rate > 0 ? $rate : 1.0, 'currency' => (string) ($currency['code'] ?? $default['code'] ?? '')];
    }

    private function price(?string $value, float $factor): ?string
    {
        if ($value === null || preg_match('/^\d{1,12}(\.\d{1,2})?$/', $value) !== 1) {
            return null;
        }

        return number_format((float) $value * $factor, 2, '.', '');
    }
}
