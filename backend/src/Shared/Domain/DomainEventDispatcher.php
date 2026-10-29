<?php

declare(strict_types=1);

namespace Cronos\Shared\Domain;

interface DomainEventDispatcher
{
    public function dispatch(object $event): void;
}
