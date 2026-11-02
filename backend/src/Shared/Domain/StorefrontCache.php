<?php

declare(strict_types=1);

namespace Cronos\Shared\Domain;

/**
 * The storefront keeps what it reads from the API in its own cache for a long time (hours or days) so that browsing almost never reaches the
 * backend. When something it may have cached changes, the module that changed it says so here, by tag ("theme", "content", "categories"…).
 * Never throws: a storefront that cannot be reached is simply refreshed by its own time limit later.
 */
interface StorefrontCache
{
    public function invalidate(string ...$tags): void;
}
