<?php

declare(strict_types=1);

namespace Cronos\Seo\Application;

/** Absolute storefront URLs. Slugs are percent-encoded; query strings and fragments never belong to a canonical URL. */
final class SeoUrls
{
    public function base(): string
    {
        return rtrim((string) (config('seo.base_url') ?: config('app.storefront_url')), '/');
    }

    /** @return list<string> */
    public function locales(): array
    {
        return array_keys(config('seo.locales'));
    }

    public function supports(string $locale): bool
    {
        return isset(config('seo.locales')[$locale]);
    }

    public function home(string $locale): string
    {
        return $this->base().'/'.config("seo.locales.{$locale}.prefix");
    }

    public function product(string $locale, string $slug): string
    {
        return $this->home($locale).'/'.config("seo.locales.{$locale}.product").'/'.rawurlencode($slug);
    }

    public function category(string $locale, string $slug): string
    {
        return $this->home($locale).'/'.config("seo.locales.{$locale}.category").'/'.rawurlencode($slug);
    }

    public function defaultLocale(): string
    {
        return (string) config('seo.default_locale');
    }

    /** @return list<array{hreflang: string, href: string}> one link per language the page exists in, plus x-default */
    public function alternates(string $kind, array $translations): array
    {
        $links = [];
        $slugs = array_column($translations, 'slug', 'locale');
        foreach ($this->locales() as $locale) { // in the order of config/seo.php, whatever order the database returned
            if (isset($slugs[$locale])) {
                $links[$locale] = ['hreflang' => $locale, 'href' => $kind === 'product' ? $this->product($locale, $slugs[$locale]) : $this->category($locale, $slugs[$locale])];
            }
        }
        if ($links === []) {
            return [];
        }
        $default = $links[$this->defaultLocale()] ?? reset($links);

        return [...array_values($links), ['hreflang' => 'x-default', 'href' => $default['href']]];
    }
}
