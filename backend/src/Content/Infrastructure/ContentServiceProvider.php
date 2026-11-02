<?php

declare(strict_types=1);

namespace Cronos\Content\Infrastructure;

use Cronos\Content\Domain\Ports\MarkdownRenderer;
use Cronos\Content\Domain\Ports\PageRepository;
use Cronos\Content\Infrastructure\Persistence\DatabasePageRepository;
use Illuminate\Support\ServiceProvider;

final class ContentServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        PageRepository::class => DatabasePageRepository::class,
        MarkdownRenderer::class => CommonMarkRenderer::class,
    ];
}
