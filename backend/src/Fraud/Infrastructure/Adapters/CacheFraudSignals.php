<?php

declare(strict_types=1);

namespace Cronos\Fraud\Infrastructure\Adapters;

use Cronos\Fraud\Domain\Ports\FraudSignals;
use Illuminate\Support\Facades\Cache;

/**
 * Per-minute buckets in the cache (Redis in production): recording is one atomic increment, counting a window
 * is one multi-get. Buckets expire on their own a day later, so nothing needs cleaning up.
 */
final class CacheFraudSignals implements FraudSignals
{
    public function record(string $kind, string $key): void
    {
        $bucket = $this->bucket($kind, $key, $this->minute());
        Cache::add($bucket, 0, now()->addMinutes(self::MAX_WINDOW_MINUTES + 5));
        Cache::increment($bucket);
    }

    public function count(string $kind, string $key, int $windowMinutes): int
    {
        $now = $this->minute();
        $keys = [];
        for ($i = 0; $i < min($windowMinutes, self::MAX_WINDOW_MINUTES); $i++) {
            $keys[] = $this->bucket($kind, $key, $now - $i);
        }

        return (int) array_sum(Cache::many($keys));
    }

    private function bucket(string $kind, string $key, int $minute): string
    {
        return "fraud:{$kind}:".sha1(strtolower($key)).":{$minute}";
    }

    private function minute(): int
    {
        return intdiv(now()->getTimestamp(), 60);
    }
}
