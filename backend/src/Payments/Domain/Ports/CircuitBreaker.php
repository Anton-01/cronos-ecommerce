<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\Ports;

/** Stops hammering a provider that is down: after repeated failures calls are refused for a cool-down, then one probe is let through. */
interface CircuitBreaker
{
    public function allows(string $name): bool;

    public function recordSuccess(string $name): void;

    public function recordFailure(string $name): void;

    public function state(string $name): string; // closed | open | half_open
}
