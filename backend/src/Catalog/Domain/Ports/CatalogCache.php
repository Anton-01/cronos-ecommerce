<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Ports;

/** Read-through cache for public catalogue queries, invalidated by tag. */
interface CatalogCache
{
    /**
     * @param  list<string>  $tags
     * @param  \Closure():mixed  $build
     */
    public function remember(array $tags, string $key, int $ttlSeconds, \Closure $build): mixed;

    /** @param list<string> $tags */
    public function flush(array $tags): void;
}
