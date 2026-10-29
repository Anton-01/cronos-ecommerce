<?php

declare(strict_types=1);

namespace Cronos\Catalog\Application;

use Cronos\Catalog\Domain\Exceptions\InvalidPreviewToken;
use Cronos\Catalog\Domain\Ports\CatalogCache;
use Cronos\Catalog\Domain\Ports\PreviewTokenStore;
use Cronos\Catalog\Domain\Ports\StorefrontCatalog;

/** Public catalogue reads, served from the tagged Redis cache. */
final class CatalogReader
{
    public const TTL = 3600;

    public const MAX_PAGE = 48;

    public function __construct(
        private readonly StorefrontCatalog $catalog,
        private readonly CatalogCache $cache,
        private readonly PreviewTokenStore $previews,
    ) {}

    /** @return list<array<string, mixed>> */
    public function categoryTree(?string $locale): array
    {
        $locale = $this->locale($locale);

        return $this->cache->remember([CatalogTags::TREE], "tree:{$locale}", self::TTL, fn () => $this->catalog->categoryTree($locale));
    }

    /** @return array<string, mixed>|null */
    public function category(?string $locale, string $slug): ?array
    {
        $locale = $this->locale($locale);

        return $this->cache->remember([CatalogTags::CATEGORIES], "category:{$locale}:{$slug}", self::TTL, fn () => $this->catalog->category($locale, $slug));
    }

    /** @return array<string, mixed>|null */
    public function product(?string $locale, string $slug): ?array
    {
        $locale = $this->locale($locale);
        // slug -> id is one indexed lookup; the expensive view is cached per product id and purged by its tag.
        $id = $this->catalog->productIdBySlug($locale, $slug);

        return $id === null ? null : $this->cache->remember([CatalogTags::PRODUCTS, CatalogTags::product($id)], "product:{$id}:{$locale}", self::TTL, fn () => $this->catalog->product($locale, $id));
    }

    /** Drafts are visible through a valid, unexpired link only, and are never cached. @return array<string, mixed> */
    public function preview(?string $locale, string $token): array
    {
        $id = $this->previews->resolve($token) ?? throw new InvalidPreviewToken;

        return $this->catalog->product($this->locale($locale), $id, preview: true) ?? throw new InvalidPreviewToken;
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array{items: list<array<string, mixed>>, total: int, hasNextPage: bool, endCursor: ?string}
     */
    public function products(?string $locale, array $filter, string $sort, int $first, ?string $after): array
    {
        $locale = $this->locale($locale);
        $first = max(1, min($first, self::MAX_PAGE));
        $offset = $this->decodeCursor($after);
        $key = 'list:'.$locale.':'.sha1(json_encode([$filter, $sort, $first, $offset], JSON_THROW_ON_ERROR));

        $page = $this->cache->remember([CatalogTags::LISTS], $key, self::TTL, fn () => $this->catalog->products($locale, $filter, $sort, $first, $offset));

        $end = $offset + count($page['items']);

        return [
            'items' => $page['items'], 'total' => $page['total'],
            'hasNextPage' => $end < $page['total'],
            'endCursor' => $page['items'] === [] ? null : base64_encode('o:'.$end),
        ];
    }

    private function locale(?string $locale): string
    {
        return in_array($locale, Localized::supported(), true) ? $locale : Localized::default();
    }

    private function decodeCursor(?string $cursor): int
    {
        if ($cursor === null) {
            return 0;
        }
        $decoded = base64_decode($cursor, true);

        return $decoded !== false && preg_match('/^o:(\d{1,9})$/', $decoded, $m) ? (int) $m[1] : 0;
    }
}
