<?php

declare(strict_types=1);

namespace Cronos\Appearance\Infrastructure;

use Cronos\Appearance\Domain\Ports\SvgSanitizer;
use Cronos\Appearance\Domain\Ports\ThemeMedia;
use Cronos\Appearance\Domain\Ports\ThemeRepository;
use Cronos\Appearance\Infrastructure\Branding\DomSvgSanitizer;
use Cronos\Appearance\Infrastructure\Branding\MediaLibraryThemeMedia;
use Cronos\Appearance\Infrastructure\Persistence\DatabaseThemeRepository;
use Illuminate\Support\ServiceProvider;

final class AppearanceServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ThemeRepository::class => DatabaseThemeRepository::class,
        SvgSanitizer::class => DomSvgSanitizer::class,
        ThemeMedia::class => MediaLibraryThemeMedia::class,
    ];
}
