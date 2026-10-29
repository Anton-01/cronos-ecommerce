<?php

declare(strict_types=1);

namespace Cronos\Payments\Infrastructure;

use Cronos\Payments\Domain\Ports\CircuitBreaker;
use Illuminate\Support\Facades\Cache;

/**
 * closed → (N failures within the window) → open for a cool-down → half-open (one probe at a time) → closed on success.
 * State lives in the cache so every PHP worker agrees on it.
 */
final class CacheCircuitBreaker implements CircuitBreaker
{
    public function __construct(private readonly int $threshold = 5, private readonly int $windowSeconds = 60, private readonly int $coolDownSeconds = 30) {}

    public function allows(string $name): bool
    {
        $state = $this->state($name);
        if ($state === 'closed') {
            return true;
        }
        if ($state === 'half_open') {
            return Cache::add("breaker:{$name}:probe", 1, 10); // exactly one request probes; the rest are refused meanwhile
        }

        return false;
    }

    public function recordSuccess(string $name): void
    {
        Cache::forget("breaker:{$name}:failures");
        Cache::forget("breaker:{$name}:open_until");
        Cache::forget("breaker:{$name}:probe");
    }

    public function recordFailure(string $name): void
    {
        Cache::add("breaker:{$name}:failures", 0, $this->windowSeconds);
        $failures = Cache::increment("breaker:{$name}:failures");
        if ($failures >= $this->threshold || $this->state($name) === 'half_open') {
            Cache::put("breaker:{$name}:open_until", now()->addSeconds($this->coolDownSeconds)->getTimestamp(), $this->coolDownSeconds * 4);
            Cache::forget("breaker:{$name}:failures");
            Cache::forget("breaker:{$name}:probe");
        }
    }

    public function state(string $name): string
    {
        $until = Cache::get("breaker:{$name}:open_until");
        if ($until === null) {
            return 'closed';
        }

        return now()->getTimestamp() < $until ? 'open' : 'half_open';
    }
}
