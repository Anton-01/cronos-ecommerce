<?php

declare(strict_types=1);

namespace Cronos\Content\Application;

use Cronos\Content\Domain\Ports\PageRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Reading side: what the storefront asks for. Answers are cached under the content version, so after the first visitor nobody reaches the
 * database; publishing or editing a page bumps the version and everything is rebuilt lazily.
 */
final class PagePresenter
{
    private const TTL = 86400;

    public function __construct(private readonly PageRepository $pages) {}

    /** @return array<string, mixed>|null */
    public function page(string $locale, string $slug, ?string $market): ?array
    {
        $market = $market === null ? null : strtoupper($market);
        $key = 'content:page:v'.PageService::version().":{$locale}:{$slug}:".($market ?? '-');

        // A missing page is cached too (as false) so a crawler hammering a wrong address does not become database load.
        $cached = Cache::remember($key, self::TTL, function () use ($locale, $slug, $market): array|false {
            $page = $this->pages->publishedBySlug($locale, $slug);
            if ($page === null || ! $this->visibleIn($page['markets'], $market)) {
                return false;
            }
            $t = $page['translation'];

            return [
                'key' => $page['key'], 'slug' => $t['slug'], 'title' => $t['title'], 'bodyHtml' => $t['body_html'], 'seoTitle' => $t['seo_title'] ?: $t['title'], 'seoDescription' => $t['seo_description'],
                'publishedAt' => $page['published_at'], 'updatedAt' => $t['updated_at'],
                'alternates' => array_values(array_filter($this->pages->alternates($page['id']), fn (array $a) => $this->pages->publishedBySlug($a['locale'], $a['slug']) !== null)),
            ];
        });

        return $cached === false ? null : $cached;
    }

    /** @return list<array{key: string, slug: string, title: string}> */
    public function list(string $locale, ?string $market): array
    {
        $market = $market === null ? null : strtoupper($market);

        return Cache::remember('content:list:v'.PageService::version().":{$locale}:".($market ?? '-'), self::TTL, fn () => array_values(array_map(
            static fn (array $p) => ['key' => $p['key'], 'slug' => $p['slug'], 'title' => $p['title']],
            array_filter($this->pages->published($locale), fn (array $p) => $this->visibleIn($p['markets'], $market)),
        )));
    }

    /** @param list<string> $markets */
    private function visibleIn(array $markets, ?string $market): bool
    {
        return $markets === [] || ($market !== null && in_array($market, $markets, true));
    }
}
