<?php

declare(strict_types=1);

namespace Cronos\Seo\Application;

final class RobotsBuilder
{
    public function __construct(private readonly SeoUrls $urls) {}

    public function build(): string
    {
        // A staging or development copy must never be indexed: the allow switch is off unless this is production.
        if (! config('seo.allow_indexing')) {
            return "User-agent: *\nDisallow: /\n";
        }
        $lines = ['User-agent: *', 'Allow: /'];
        foreach ($this->urls->locales() as $locale) {
            foreach (config('seo.noindex_paths') as $path) {
                $lines[] = 'Disallow: /'.config("seo.locales.{$locale}.prefix").'/'.$path;
            }
        }
        // Sorted and filtered listings are the same products again: only the clean listing is indexed.
        foreach (['sort', 'price_min', 'price_max', 'attr'] as $param) {
            $lines[] = "Disallow: /*?*{$param}=";
        }
        $lines[] = '';
        $lines[] = 'Sitemap: '.$this->urls->base().'/sitemap.xml';

        return implode("\n", $lines)."\n";
    }
}
