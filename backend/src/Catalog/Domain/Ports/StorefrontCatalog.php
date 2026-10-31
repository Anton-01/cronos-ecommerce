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

    /**
     * Summaries of specific products, in the order given; products that are not published (or no longer exist) are left out.
     *
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    public function summariesOf(string $locale, array $ids): array;

    /**
     * Published, sellable-by-URL products that have a page in this language, for the sitemap (variations are not pages of their own).
     *
     * @return list<array{slug: string, updated_at: string, alternates: list<array{locale: string, slug: string}>}>
     */
    public function sitemapProducts(string $locale, int $limit, int $offset): array;

    public function sitemapProductCount(string $locale): int;

    /** @return list<array{slug: string, updated_at: string, alternates: list<array{locale: string, slug: string}>}> active categories (whole branch active) with a page in this language */
    public function sitemapCategories(string $locale): array;
}
