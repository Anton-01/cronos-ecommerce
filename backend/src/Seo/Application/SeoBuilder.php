<?php

declare(strict_types=1);

namespace Cronos\Seo\Application;

use Cronos\Catalog\Domain\Ports\StorefrontCatalog;
use Cronos\Inventory\Application\MarketStock;
use Cronos\Markets\Domain\Ports\MarketProfileRepository;
use Cronos\Pricing\Application\PriceQuoter;
use Throwable;

/**
 * The tags a search engine and a social network read: title, description, canonical, hreflang, Open Graph and Schema.org. Built from the
 * same catalogue the shopper sees, so a page's metadata can never describe something other than the page.
 */
final class SeoBuilder
{
    public function __construct(
        private readonly StorefrontCatalog $catalog,
        private readonly SeoUrls $urls,
        private readonly PriceQuoter $prices,
        private readonly MarketStock $stock,
        private readonly MarketProfileRepository $markets,
    ) {}

    /** @return array<string, mixed> */
    public function home(string $locale, ?string $market = null): array
    {
        $name = $this->siteName($locale, $market);
        $profile = $this->profile($locale, $market);
        $url = $this->urls->home($locale);
        $description = __('seo.home_description', ['site' => $name], $locale);

        return $this->page(
            $locale, $name, $name, $description, $url, 'index,follow', array_map(fn (string $l) => ['hreflang' => $l, 'href' => $this->urls->home($l)], $this->urls->locales()) + [],
            'website', null, [
                $this->organization($profile, $name, $url),
                ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $name, 'url' => $url,
                    'potentialAction' => ['@type' => 'SearchAction', 'target' => $url.'/search?q={search_term_string}', 'query-input' => 'required name=search_term_string']],
            ], true,
        );
    }

    /** @return array<string, mixed>|null null when there is no published product with that slug */
    public function product(string $locale, string $slug, ?string $market = null): ?array
    {
        $id = $this->catalog->productIdBySlug($locale, $slug);
        $p = $id === null ? null : $this->catalog->product($locale, $id);
        if ($p === null) {
            return null;
        }
        $market ??= $this->marketOf($locale);
        $site = $this->siteName($locale, $market);
        $own = collect($p['seo']['alternates'])->firstWhere('locale', $locale)['slug'] ?? $p['slug'] ?? $slug;
        $url = $this->urls->product($locale, $own);
        $description = $this->plain($p['seo']['description'] ?? $p['shortDescription'] ?? null) ?? __('seo.product_description', ['name' => $p['name'], 'site' => $site], $locale);
        $image = $p['gallery'][0]['url'] ?? null;
        $offer = $market === null ? null : $this->offer($p, $market, $url);

        $ld = [[
            '@context' => 'https://schema.org', '@type' => 'Product', 'name' => $p['name'], 'description' => $description, 'sku' => $p['sku'], 'url' => $url,
            'image' => array_values(array_filter(array_map(fn (array $g) => $g['url'] ?? null, $p['gallery']))), 'offers' => $offer?->jsonLd,
        ]];
        $ld[0] = array_filter($ld[0], static fn ($v) => $v !== null && $v !== [] && $v !== '');
        $primary = collect($p['categories'])->firstWhere('isPrimary', true) ?? ($p['categories'][0] ?? null);
        $crumbs = [['name' => $site, 'url' => $this->urls->home($locale)]];
        $primary !== null && $primary['slug'] !== null && $crumbs[] = ['name' => $primary['name'], 'url' => $this->urls->category($locale, $primary['slug'])];
        $crumbs[] = ['name' => $p['name'], 'url' => $url];
        $ld[] = $this->breadcrumbs($crumbs);

        $page = $this->page($locale, $site, $this->title($p['seo']['title'] ?? $p['name'], $site), $description, $url, 'index,follow', $this->urls->alternates('product', $p['seo']['alternates']), 'product', $image, $ld);
        $page['openGraph'] = array_merge($page['openGraph'], ['priceAmount' => $offer?->amount, 'priceCurrency' => $offer?->currency, 'availability' => $offer?->ogAvailability]);

        return $page;
    }

    /** @return array<string, mixed>|null */
    public function category(string $locale, string $slug, ?string $market = null): ?array
    {
        $c = $this->catalog->category($locale, $slug);
        if ($c === null) {
            return null;
        }
        $site = $this->siteName($locale, $market ?? $this->marketOf($locale));
        $own = collect($c['seo']['alternates'] ?? [])->firstWhere('locale', $locale)['slug'] ?? $c['slug'] ?? $slug;
        $url = $this->urls->category($locale, $own);
        $description = $this->plain($c['seo']['description'] ?? $c['description'] ?? null) ?? __('seo.category_description', ['name' => $c['name'], 'site' => $site], $locale);
        $crumbs = [['name' => $site, 'url' => $this->urls->home($locale)]];
        foreach ($c['breadcrumb'] as $a) {
            $a['slug'] !== null && $crumbs[] = ['name' => $a['name'], 'url' => $this->urls->category($locale, $a['slug'])];
        }
        $crumbs[] = ['name' => $c['name'], 'url' => $url];

        return $this->page($locale, $site, $this->title($c['seo']['title'] ?? $c['name'], $site), $description, $url, 'index,follow', $this->urls->alternates('category', $c['seo']['alternates'] ?? []),
            'website', $c['image']['url'] ?? null, [$this->breadcrumbs($crumbs)]);
    }

    /**
     * @param  list<array{hreflang: string, href: string}>  $alternates
     * @param  list<array<string, mixed>>  $jsonLd
     * @return array<string, mixed>
     */
    private function page(string $locale, string $site, string $title, string $description, string $canonical, string $robots, array $alternates, string $ogType, ?string $image, array $jsonLd, bool $home = false): array
    {
        $og = config("seo.locales.{$locale}.og");
        $others = array_values(array_filter(array_map(fn (string $l) => $l === $locale ? null : config("seo.locales.{$l}.og"), $this->urls->locales())));
        if ($home) {
            $alternates = [...array_values($alternates), ['hreflang' => 'x-default', 'href' => $this->urls->home($this->urls->defaultLocale())]];
        }

        return [
            'title' => $title, 'description' => $description, 'canonical' => $canonical, 'robots' => $robots, 'alternates' => $alternates,
            'openGraph' => ['type' => $ogType, 'title' => $title, 'description' => $description, 'url' => $canonical, 'image' => $image, 'siteName' => $site,
                'locale' => $og, 'alternateLocales' => $others, 'priceAmount' => null, 'priceCurrency' => null, 'availability' => null],
            'twitter' => ['card' => $image === null ? 'summary' : 'summary_large_image', 'title' => $title, 'description' => $description, 'image' => $image],
            // JSON_HEX_TAG: a product named "</script><script>…" cannot break out of the <script type="application/ld+json"> block.
            'jsonLd' => array_map(static fn (array $d) => (string) json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PRESERVE_ZERO_FRACTION), $jsonLd),
        ];
    }

    /** @param array<string, mixed> $p @return object{jsonLd: array<string, mixed>, amount: string, currency: string, ogAvailability: string}|null */
    private function offer(array $p, string $market, string $url): ?object
    {
        $ids = $p['type'] === 'variable' ? array_column($p['variations'], 'id') : [$p['id']];
        try {
            $quotes = array_filter($this->prices->quote($ids, $market));
        } catch (Throwable) {
            return null; // a market that does not exist, or one that does not price this product: no offer is better than a wrong one
        }
        if ($quotes === []) {
            return null;
        }
        $amounts = array_map(static fn (array $q) => $q['price']->value(), $quotes);
        sort($amounts, SORT_NUMERIC);
        $currency = reset($quotes)['currency'];
        $status = $this->stock->status($p['id'], $market);
        $availability = ['IN_STOCK' => 'https://schema.org/InStock', 'ON_BACKORDER' => 'https://schema.org/BackOrder', 'OUT_OF_STOCK' => 'https://schema.org/OutOfStock'][$status];
        $seller = $this->markets->profile($market);
        $sellerLd = ($seller['legal_name'] ?? null) ? ['@type' => 'Organization', 'name' => $seller['legal_name']] : null;
        $ends = collect($quotes)->pluck('sale_ends_at')->filter()->sort()->first();

        $ld = count($amounts) > 1 && $p['type'] === 'variable'
            ? ['@type' => 'AggregateOffer', 'lowPrice' => $amounts[0], 'highPrice' => end($amounts), 'priceCurrency' => $currency, 'offerCount' => count($amounts), 'availability' => $availability]
            : ['@type' => 'Offer', 'price' => $amounts[0], 'priceCurrency' => $currency, 'availability' => $availability, 'url' => $url, 'itemCondition' => 'https://schema.org/NewCondition'];
        $sellerLd === null || $ld['seller'] = $sellerLd;
        $ends === null || $ld['priceValidUntil'] = substr((string) $ends, 0, 10);

        return (object) ['jsonLd' => $ld, 'amount' => $amounts[0], 'currency' => $currency, 'ogAvailability' => ['IN_STOCK' => 'instock', 'ON_BACKORDER' => 'backorder', 'OUT_OF_STOCK' => 'oos'][$status]];
    }

    /** @param array<string, mixed>|null $profile @return array<string, mixed> */
    private function organization(?array $profile, string $name, string $url): array
    {
        $a = $profile['fiscal_address'] ?? [];
        $ld = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => $profile['trade_name'] ?? $name, 'legalName' => $profile['legal_name'] ?? null, 'url' => $url,
            'email' => $profile['contact_email'] ?? null, 'telephone' => $profile['contact_phone'] ?? null,
            'address' => $a === [] ? null : array_filter(['@type' => 'PostalAddress', 'streetAddress' => trim(($a['street'] ?? '').' '.($a['exterior_no'] ?? '')), 'addressLocality' => $a['city'] ?? null,
                'addressRegion' => $a['state'] ?? null, 'postalCode' => $a['zip'] ?? null, 'addressCountry' => $a['country_code'] ?? null])];

        return array_filter($ld, static fn ($v) => $v !== null && $v !== '');
    }

    /** @param list<array{name: string, url: string}> $items @return array<string, mixed> */
    private function breadcrumbs(array $items): array
    {
        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(static fn (array $c, int $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name'], 'item' => $c['url']], $items, array_keys($items))];
    }

    private function title(string $title, string $site): string
    {
        $title = trim($title);

        return mb_strlen($title) + mb_strlen($site) + 3 <= 60 && stripos($title, $site) === false ? "{$title} | {$site}" : $title;
    }

    /** HTML out, whitespace collapsed, cut at a word before 160 characters. */
    private function plain(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $t = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text))));
        if ($t === '') {
            return null;
        }
        if (mb_strlen($t) <= 160) {
            return $t;
        }
        $cut = mb_substr($t, 0, 157);

        return rtrim((string) (mb_strrpos($cut, ' ') > 100 ? mb_substr($cut, 0, (int) mb_strrpos($cut, ' ')) : $cut), ' ,.;:').'…';
    }

    /** The store that speaks this language (es-MX → Mexico), when there is exactly a natural choice. */
    private function marketOf(string $locale): ?string
    {
        foreach ($this->markets->profiles() as $m) {
            if ($m['default_locale'] === $locale && $m['is_active']) {
                return $m['code'];
            }
        }

        return null;
    }

    private function siteName(string $locale, ?string $market): string
    {
        return $this->profile($locale, $market)['trade_name'] ?? (string) config('seo.site_name');
    }

    /** @return array<string, mixed>|null */
    private function profile(string $locale, ?string $market): ?array
    {
        $code = $market ?? $this->marketOf($locale);

        return $code === null ? null : $this->markets->profile($code);
    }
}
