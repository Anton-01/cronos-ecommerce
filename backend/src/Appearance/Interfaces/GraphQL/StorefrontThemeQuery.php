<?php

declare(strict_types=1);

namespace Cronos\Appearance\Interfaces\GraphQL;

use Cronos\Appearance\Application\ThemePresenter;
use Cronos\Appearance\Application\ThemeService;

final class StorefrontThemeQuery
{
    public function __construct(private readonly ThemePresenter $presenter, private readonly ThemeService $themes) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args): array
    {
        $draft = isset($args['preview']) ? $this->themes->draftForToken($args['preview']) : null; // a bad or stale token just shows the live look

        return $this->presenter->storefront($args['locale'] ?? (string) config('app.locale_default'), $args['market'] ?? null, $draft);
    }
}
