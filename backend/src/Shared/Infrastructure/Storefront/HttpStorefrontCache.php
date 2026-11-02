<?php

declare(strict_types=1);

namespace Cronos\Shared\Infrastructure\Storefront;

use Cronos\Shared\Domain\StorefrontCache;

final class HttpStorefrontCache implements StorefrontCache
{
    public function invalidate(string ...$tags): void
    {
        if ($tags === [] || (string) config('storefront.revalidate_secret') === '') {
            return;
        }
        // After the database commit, so the storefront never refetches the old data.
        RevalidateStorefrontJob::dispatch(array_values(array_unique($tags)))->afterCommit();
    }
}
