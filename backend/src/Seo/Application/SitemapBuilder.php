<?php

declare(strict_types=1);

namespace Cronos\Seo\Application;

use Cronos\Catalog\Domain\Ports\StorefrontCatalog;
use Cronos\Content\Domain\Ports\PageRepository;
use Illuminate\Support\Facades\Cache;

/**
 * sitemap.xml index plus one file per language and page type, each with hreflang alternates. Large catalogues are split in pages of
 * `sitemap_page_size` URLs (the protocol allows 50,000). Built from the live catalogue and cached for a few minutes.
 */
final class SitemapBuilder
{
    public function __construct(private readonly StorefrontCatalog $catalog, private readonly SeoUrls $urls, private readonly PageRepository $pages) {}

    public function index(): string
    {
        return $this->cached('index', function (): string {
            $items = '';
            foreach ($this->urls->locales() as $locale) {
                $items .= $this->sitemapEntry($this->urls->base().'/sitemaps/'.$locale.'-pages.xml');
                $pages = max(1, (int) ceil($this->catalog->sitemapProductCount($locale) / $this->pageSize()));
                for ($n = 1; $n <= $pages; $n++) {
                    $items .= $this->sitemapEntry($this->urls->base()."/sitemaps/{$locale}-products-{$n}.xml");
                }
            }

            return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$items.'</sitemapindex>';
        });
    }

    /** @param string $name "es-MX-pages" or "es-MX-products-2" @return string|null null when no such file exists */
    public function file(string $name): ?string
    {
        if (preg_match('/^([A-Za-z]{2}-[A-Za-z]{2})-(pages|products-(\d{1,6}))$/', $name, $m) !== 1 || ! $this->urls->supports($m[1])) {
            return null;
        }
        [$locale, $kind] = [$m[1], $m[2]];

        return $this->cached('file:'.$name, function () use ($locale, $kind, $m): ?string {
            if ($kind === 'pages') {
                $urls = $this->entry($this->urls->home($locale), null, array_map(fn (string $l) => ['hreflang' => $l, 'href' => $this->urls->home($l)], $this->urls->locales()), 'daily', '1.0');
                foreach ($this->catalog->sitemapCategories($locale) as $c) {
                    $urls .= $this->entry($this->urls->category($locale, $c['slug']), $c['updated_at'], $this->urls->alternates('category', $c['alternates']), 'weekly', '0.7');
                }

                // Static pages for every store (a page limited to some stores is left out: the sitemap cannot say which store a crawler is on).
                foreach ($this->pages->published($locale) as $p) {
                    $p['markets'] === [] && $urls .= $this->entry($this->urls->page($locale, $p['slug']), null, $this->urls->alternates('page', $this->pages->alternates($p['id'])), 'monthly', '0.4');
                }

                return $this->urlset($urls);
            }
            $page = (int) $m[3];
            $rows = $this->catalog->sitemapProducts($locale, $this->pageSize(), ($page - 1) * $this->pageSize());
            if ($rows === [] && $page > 1) {
                return null;
            }
            $urls = '';
            foreach ($rows as $p) {
                $urls .= $this->entry($this->urls->product($locale, $p['slug']), $p['updated_at'], $this->urls->alternates('product', $p['alternates']), 'weekly', '0.8');
            }

            return $this->urlset($urls);
        });
    }

    private function pageSize(): int
    {
        return max(100, min(50000, (int) config('seo.sitemap_page_size')));
    }

    /** @param list<array{hreflang: string, href: string}> $alternates */
    private function entry(string $loc, ?string $lastmod, array $alternates, string $freq, string $priority): string
    {
        $links = '';
        foreach ($alternates as $a) {
            $links .= '<xhtml:link rel="alternate" hreflang="'.$this->x($a['hreflang']).'" href="'.$this->x($a['href']).'"/>';
        }

        return '<url><loc>'.$this->x($loc).'</loc>'.($lastmod === null ? '' : '<lastmod>'.$this->x($lastmod).'</lastmod>').'<changefreq>'.$freq.'</changefreq><priority>'.$priority.'</priority>'.$links.'</url>';
    }

    private function sitemapEntry(string $loc): string
    {
        return '<sitemap><loc>'.$this->x($loc).'</loc></sitemap>';
    }

    private function urlset(string $urls): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'.$urls.'</urlset>';
    }

    private function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function cached(string $key, callable $build): ?string
    {
        return Cache::remember('seo:sitemap:'.$key, (int) config('seo.sitemap_cache_seconds'), $build);
    }
}
