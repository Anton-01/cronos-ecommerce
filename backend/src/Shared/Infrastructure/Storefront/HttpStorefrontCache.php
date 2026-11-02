<?php

declare(strict_types=1);

namespace Cronos\Shared\Infrastructure\Storefront;

use Cronos\Shared\Domain\StorefrontCache;
use Illuminate\Support\Facades\Cache;

final class HttpStorefrontCache implements StorefrontCache
{
    private const WINDOW = 3;

    public function invalidate(string ...$tags): void
    {
        if ($tags === [] || (string) config('storefront.revalidate_secret') === '') {
            return;
        }
        // A burst of changes (every order moves stock) becomes one notice: the first change of a tag opens a short window, the notice goes out when
        // it closes and covers everything that changed inside it. After the commit, so the storefront never refetches the old data.
        $fresh = array_values(array_filter(array_unique($tags), static fn (string $t) => Cache::add("storefront:revalidate:{$t}", 1, self::WINDOW)));
        $fresh === [] || RevalidateStorefrontJob::dispatch($fresh)->delay(self::WINDOW)->afterCommit();
    }
}
