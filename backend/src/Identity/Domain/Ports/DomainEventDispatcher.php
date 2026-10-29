<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\Ports;

interface DomainEventDispatcher
{
    public function dispatch(object $event): void;
}
