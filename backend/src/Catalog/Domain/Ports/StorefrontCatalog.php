<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Ports;

/** What customers see: published data only, already resolved for one locale. Everything returned is plain arrays. */
interface StorefrontCatalog
{
    /** @return list<array<string, mixed>> nested active categories */
    public function categoryTree(string $locale): array;

    /** @return array<string, mixed>|null */
    public function category(string $locale, string $slug): ?array;

    /** Finds a product by slug in the locale, or in any locale so old links can be redirected. */
    public function productIdBySlug(string $locale, string $slug): ?string;

    /**
     * @param  bool  $preview  show drafts too (only reachable with a valid preview token)
     * @return array<string, mixed>|null
     */
    public function product(string $locale, string $id, bool $preview = false): ?array;

    /**
     * @param  array{category?: ?string, tag?: ?string, search?: ?string, price_min?: ?string, price_max?: ?string, attributes?: list<array{code: string, values: list<string>}>}  $filter
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function products(string $locale, array $filter, string $sort, int $limit, int $offset): array;
}
