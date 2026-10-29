<?php

declare(strict_types=1);

namespace Cronos\Settings\Infrastructure\Adapters;

use Cronos\Settings\Domain\Ports\SettingsSnapshotStore;
use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/** Keeps the snapshot in Redis with no TTL; it is only ever replaced by a write-through refresh. */
final class CacheSettingsSnapshotStore implements SettingsSnapshotStore
{
    private const KEY = 'settings:snapshot:v1';

    private const TAG = 'settings';

    public function get(): ?array
    {
        $value = $this->cache()->get(self::KEY);

        return is_array($value) ? $value : null;
    }

    public function put(array $snapshot): void
    {
        $this->cache()->forever(self::KEY, $snapshot);
    }

    public function forget(): void
    {
        $this->cache()->forget(self::KEY);
    }

    public function remember(callable $build): array
    {
        if (($hit = $this->get()) !== null) {
            return $hit;
        }

        // Cold cache: one process rebuilds while the others wait for its result.
        return Cache::lock('settings:snapshot:build', 15)->block(10, function () use ($build) {
            $hit = $this->get();

            if ($hit === null) {
                $hit = $build();
                $this->put($hit);
            }

            return $hit;
        });
    }

    private function cache(): Repository
    {
        $store = Cache::store();

        return $store->getStore() instanceof TaggableStore ? $store->tags([self::TAG]) : $store;
    }
}
