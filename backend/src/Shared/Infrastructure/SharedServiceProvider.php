<?php

declare(strict_types=1);

namespace Cronos\Shared\Infrastructure;

use Cronos\Shared\Domain\DomainEventDispatcher;
use Illuminate\Support\ServiceProvider;

final class SharedServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        DomainEventDispatcher::class => LaravelDomainEventDispatcher::class,
    ];
}
