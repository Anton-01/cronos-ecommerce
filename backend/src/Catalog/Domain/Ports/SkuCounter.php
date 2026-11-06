<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Ports;

interface SkuCounter
{
    /** The next number of a prefix, taken atomically: two callers never receive the same one. */
    public function take(string $prefix): int;
}
