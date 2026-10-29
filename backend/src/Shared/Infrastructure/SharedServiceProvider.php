<?php

declare(strict_types=1);

namespace Cronos\Shared\Infrastructure;

use Cronos\Shared\Domain\DomainEventDispatcher;
use Cronos\Shared\Domain\Geo\GeoLocator;
use Cronos\Shared\Infrastructure\Console\ImportGeoRangesCommand;
use Cronos\Shared\Infrastructure\Geo\CurrentClient;
use Cronos\Shared\Infrastructure\Geo\DatabaseGeoLocator;
use Illuminate\Support\ServiceProvider;

final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One resolved caller (IP -> country, clock) per request / queued job.
        $this->app->scoped(CurrentClient::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ImportGeoRangesCommand::class]);
        }
    }

    /** @var array<class-string, class-string> */
    public array $bindings = [
        DomainEventDispatcher::class => LaravelDomainEventDispatcher::class,
        GeoLocator::class => DatabaseGeoLocator::class,
    ];
}
