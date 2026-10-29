<?php

declare(strict_types=1);

namespace Cronos\Shared\Infrastructure;

use Cronos\Shared\Domain\DomainEventDispatcher;
use Illuminate\Contracts\Events\Dispatcher;

final class LaravelDomainEventDispatcher implements DomainEventDispatcher
{
    public function __construct(private readonly Dispatcher $events) {}

    public function dispatch(object $event): void
    {
        $this->events->dispatch($event);
    }
}
