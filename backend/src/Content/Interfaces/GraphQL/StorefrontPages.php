<?php

declare(strict_types=1);

namespace Cronos\Content\Interfaces\GraphQL;

use Cronos\Content\Application\PagePresenter;

final class StorefrontPages
{
    public function __construct(private readonly PagePresenter $pages) {}

    /** @param array<string, mixed> $args */
    public function page(mixed $root, array $args): ?array
    {
        return $this->pages->page($this->locale($args['locale'] ?? null), mb_substr((string) $args['slug'], 0, 80), $args['market'] ?? null);
    }

    /** @param array<string, mixed> $args */
    public function list(mixed $root, array $args): array
    {
        return $this->pages->list($this->locale($args['locale'] ?? null), $args['market'] ?? null);
    }

    private function locale(?string $requested): string
    {
        return in_array($requested, config('app.supported_locales'), true) ? $requested : (string) config('app.locale_default');
    }
}
