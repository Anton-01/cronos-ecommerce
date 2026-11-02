<?php

declare(strict_types=1);

namespace Cronos\Appearance\Application;

use Cronos\Appearance\Domain\Ports\ThemeMedia;
use Cronos\Appearance\Domain\Services\ThemeCss;
use Cronos\Appearance\Domain\Services\ThemeSchema;
use Cronos\Markets\Domain\Ports\MarketProfileRepository;
use Cronos\Pricing\Domain\Ports\PricingCatalog;
use Illuminate\Support\Facades\Cache;

/** What the storefront (and the admin preview) receives: tokens resolved to CSS, pictures resolved to URLs, texts in the visitor's language. */
final class ThemePresenter
{
    public function __construct(
        private readonly ThemeService $themes,
        private readonly ThemeMedia $media,
        private readonly MarketProfileRepository $markets,
        private readonly PricingCatalog $pricing,
    ) {}

    /** @return array<string, mixed> */
    public function storefront(string $locale, ?string $market, ?array $version = null): array
    {
        $locale = in_array($locale, $this->themes->locales(), true) ? $locale : $this->themes->locales()[0];
        $market = $market === null ? null : strtoupper($market);
        if ($version !== null) {
            return $this->build($version, $locale, $market); // a draft preview is never cached
        }
        $live = $this->themes->live();

        return Cache::remember("theme:public:v{$live['number']}:{$locale}:".($market ?? '-'), 600, fn () => $this->build($live, $locale, $market));
    }

    /** @param array<string, mixed> $v @return array<string, mixed> */
    private function build(array $v, string $locale, ?string $market): array
    {
        $vars = ThemeCss::variables($v['tokens']);
        $profile = $market === null ? null : $this->markets->profile($market);
        $siteName = $profile['trade_name'] ?? $v['tokens']['identity']['siteName'];
        $assets = $this->assets($v['assets'], $siteName);

        return [
            'version' => $v['number'], 'publishedAt' => $v['published_at'], 'siteName' => $siteName, 'tagline' => $this->pick($v['tokens']['identity']['tagline'], $locale),
            'themeColor' => $v['tokens']['identity']['themeColor'] ?? $v['tokens']['colors']['primary'], 'tokens' => $v['tokens'], 'cssVariables' => $vars, 'css' => ThemeCss::css($vars),
            'fontsHref' => ThemeCss::fontsHref($v['tokens']), 'assets' => $assets, 'navigation' => $this->navigation($v['navigation'], $locale, $market),
            'home' => $this->home($v['home'], $locale), 'features' => $v['features'], 'manifestUrl' => url('/manifest.webmanifest'),
        ];
    }

    /** @param array<string, array<string, mixed>> $assets @return array<string, array<string, mixed>> */
    private function assets(array $assets, string $alt): array
    {
        $out = [];
        foreach ($assets as $slot => $a) {
            $files = (array) ($a['files'] ?? []);
            $best = collect($files)->first(fn ($f) => in_array($f['format'], ['svg', 'webp'], true)) ?? ($files[0] ?? null);
            $srcset = collect($files)->where('format', 'webp')->map(fn ($f) => $f['url'].' '.$f['density'].'x')->implode(', ');
            $out[$slot] = ['kind' => $a['kind'], 'hash' => $a['hash'], 'url' => $best['url'] ?? null, 'width' => $best['width'] ?? null, 'height' => $best['height'] ?? null,
                'srcset' => $srcset === '' ? null : $srcset, 'alt' => $alt, 'files' => array_map(fn ($f) => array_diff_key($f, ['path' => 1]), $files)];
        }

        return $out;
    }

    /** @param array<string, mixed> $nav @return array<string, mixed> */
    private function navigation(array $nav, string $locale, ?string $market): array
    {
        $announcements = [];
        foreach ($nav['announcements'] as $a) {
            ($a['markets'] === [] || ($market !== null && in_array($market, $a['markets'], true))) && $announcements[] = ['type' => 'text', 'text' => $this->pick($a['text'], $locale), 'url' => $a['url']];
        }
        // The shipping ribbon writes itself from the store's own threshold: change it in "Tiendas por país" and the ribbon follows.
        if ($nav['showFreeShippingRibbon'] && $market !== null) {
            $free = $this->markets->profile($market)['shipping_free_over'] ?? null;
            $currency = $this->currencyOf($market);
            if ($free !== null && (float) $free > 0 && $currency !== null) {
                $amount = class_exists(\NumberFormatter::class) ? (new \NumberFormatter($locale, \NumberFormatter::CURRENCY))->formatCurrency((float) $free, $currency) : "{$free} {$currency}";
                array_unshift($announcements, ['type' => 'free_shipping', 'text' => __('theme.free_shipping', ['amount' => $amount], $locale), 'url' => null, 'amount' => $free, 'currency' => $currency]);
            }
        }

        return [
            'topbarLinks' => array_map(fn ($l) => ['label' => $this->pick($l['label'], $locale), 'url' => $l['url']], $nav['topbarLinks']),
            'footerColumns' => array_map(fn ($c) => ['title' => $this->pick($c['title'], $locale), 'links' => array_map(fn ($l) => ['label' => $this->pick($l['label'], $locale), 'url' => $l['url']], $c['links'])], $nav['footerColumns']),
            'social' => $nav['social'], 'announcements' => $announcements, 'footerNote' => $this->pick($nav['footerNote'], $locale),
        ];
    }

    /** @param list<array<string, mixed>> $home @return list<array<string, mixed>> */
    private function home(array $home, string $locale): array
    {
        $ids = [];
        array_walk_recursive($home, function ($v, $k) use (&$ids): void {
            in_array($k, ['image', 'imageMobile'], true) && is_string($v) && $v !== '' && $ids[$v] = true;
        });
        $images = $this->media->describe(array_keys($ids));
        $resolve = function ($node) use (&$resolve, $images, $locale) {
            if (! is_array($node)) {
                return $node;
            }
            $out = [];
            foreach ($node as $k => $v) {
                if (in_array($k, ['image', 'imageMobile'], true) && is_string($v) && $v !== '') {
                    $img = $images[$v] ?? null;
                    $out[$k] = $img === null ? null : ['url' => $img['url'], 'srcset' => $img['srcset'], 'srcsetAvif' => $img['srcsetAvif'], 'width' => $img['width'], 'height' => $img['height'], 'alt' => $this->pick($img['alt'], $locale), 'focal' => $img['focal']];

                    continue;
                }
                $out[$k] = $resolve($v);
            }

            return $this->isTextMap($out) ? $this->pick($out, $locale) : $out;
        };

        return array_values(array_map($resolve, array_values(array_filter($home, fn ($s) => $s['enabled'] && empty($s['reserved'])))));
    }

    /** @param array<string, mixed> $a */
    private function isTextMap(array $a): bool
    {
        return $a !== [] && count(array_diff(array_keys($a), $this->themes->locales())) === 0 && count(array_filter($a, 'is_string')) === count($a);
    }

    /** The text in the visitor's language, falling back to the default language and then to any that is filled. @param array<string, string> $texts */
    private function pick(array $texts, string $locale): string
    {
        foreach ([$locale, $this->themes->locales()[0]] as $l) {
            if (isset($texts[$l]) && $texts[$l] !== '') {
                return $texts[$l];
            }
        }

        return (string) (array_values(array_filter($texts))[0] ?? '');
    }

    private function currencyOf(string $market): ?string
    {
        $snap = $this->pricing->snapshot();
        $m = collect($snap['markets'])->firstWhere('code', $market);

        return $m === null ? null : (collect($snap['currencies'])->firstWhere('id', $m['currency_id'])['code'] ?? null);
    }

    /**
     * Everything the admin screen needs about a version: the document as saved plus what it renders to.
     *
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    public function admin(array $v): array
    {
        $vars = ThemeCss::variables($v['tokens']);

        return [
            'id' => $v['id'], 'number' => $v['number'], 'status' => $v['status'], 'name' => $v['name'], 'notes' => $v['notes'], 'updatedAt' => $v['updated_at'], 'publishedAt' => $v['published_at'],
            'tokens' => $v['tokens'], 'navigation' => $v['navigation'], 'home' => $v['home'], 'features' => $v['features'], 'assets' => $this->assets($v['assets'], $v['tokens']['identity']['siteName']),
            'cssVariables' => $vars, 'fontsHref' => ThemeCss::fontsHref($v['tokens']), 'contrast' => ThemeSchema::contrast($v['tokens']),
        ];
    }
}
