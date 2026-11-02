<?php

declare(strict_types=1);

namespace Cronos\Seo\Interfaces\GraphQL;

use Cronos\Seo\Application\SeoBuilder;
use Cronos\Seo\Application\SeoUrls;
use GraphQL\Error\UserError;

final class SeoQuery
{
    public function __construct(private readonly SeoBuilder $seo, private readonly SeoUrls $urls) {}

    /** @param array<string, mixed> $args */
    public function meta(mixed $root, array $args): ?array
    {
        $this->urls->supports($args['locale']) || throw new UserError('Unsupported locale: '.implode(', ', $this->urls->locales()).'.');
        $slug = $args['slug'] ?? null;

        return match ($args['target']) {
            'HOME' => $this->seo->home($args['locale'], $args['market'] ?? null),
            'PRODUCT' => $slug === null ? throw new UserError('A product page needs its slug.') : $this->seo->product($args['locale'], $slug, $args['market'] ?? null),
            'CATEGORY' => $slug === null ? throw new UserError('A category page needs its slug.') : $this->seo->category($args['locale'], $slug, $args['market'] ?? null),
        };
    }

    /** @return list<array<string, string>> */
    public function routes(): array
    {
        return array_map(static fn (string $l, array $r) => ['locale' => $l, 'prefix' => $r['prefix'], 'productSegment' => $r['product'], 'categorySegment' => $r['category'], 'pageSegment' => $r['page'], 'ogLocale' => $r['og']], array_keys(config('seo.locales')), array_values(config('seo.locales')));
    }
}
