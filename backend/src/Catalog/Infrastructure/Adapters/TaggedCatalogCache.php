<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Adapters;

use Cronos\Catalog\Domain\Ports\CatalogCache;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;

/**
 * Redis tagged cache. On a store without tag support (file/database) it degrades to "no cache":
 * slower, never stale.
 */
final class TaggedCatalogCache implements CatalogCache
{
    public function remember(array $tags, string $key, int $ttlSeconds, \Closure $build): mixed
    {
        if (! $this->taggable()) {
            return $build();
        }

        $cache = Cache::tags($tags);
        $hit = $cache->get($key);

        if ($hit !== null) {
            return $hit;
        }

        // One process rebuilds a cold key; concurrent requests wait for its result instead of stampeding the database.
        return Cache::lock('catalog:build:'.sha1($key), 10)->block(5, function () use ($cache, $key, $ttlSeconds, $build) {
            $hit = $cache->get($key);

            if ($hit === null) {
                $hit = $build();
                $cache->put($key, $hit, $ttlSeconds);
            }

            return $hit;
        });
    }

    public function flush(array $tags): void
    {
        $this->taggable() && Cache::tags($tags)->flush();
    }

    private function taggable(): bool
    {
        return Cache::getStore() instanceof TaggableStore;
    }
}
