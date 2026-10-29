<?php

declare(strict_types=1);

namespace Cronos\Catalog\Interfaces\GraphQL\Queries;

use Cronos\Catalog\Application\CatalogReader;

final class StorefrontCatalogQuery
{
    public function __construct(private readonly CatalogReader $reader) {}

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
        $filter = array_filter([
            'category' => $args['category'] ?? null,
            'tag' => $args['tag'] ?? null,
            'search' => $args['search'] ?? null,
            'price_min' => $this->price($args['priceMin'] ?? null),
            'price_max' => $this->price($args['priceMax'] ?? null),
            'attributes' => array_map(fn (array $a) => ['code' => $a['code'], 'values' => array_values($a['values'])], $args['attributes'] ?? []),
        ], fn ($v) => $v !== null && $v !== []);

        return $this->reader->products($args['locale'] ?? null, $filter, $args['sort'] ?? 'NEWEST', (int) ($args['first'] ?? 24), $args['after'] ?? null);
    }

    private function price(?string $value): ?string
    {
        return $value !== null && preg_match('/^\d{1,12}(\.\d{1,2})?$/', $value) ? $value : null;
    }
}
