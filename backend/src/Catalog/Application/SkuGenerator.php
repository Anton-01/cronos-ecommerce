<?php

declare(strict_types=1);

namespace Cronos\Catalog\Application;

use Cronos\Catalog\Domain\Ports\ProductRepository;
use Cronos\Catalog\Domain\Ports\SkuCounter;

/** Consecutive SKUs per prefix (LIS-00042). Skips any number already taken by hand so a typed SKU never blocks the sequence. */
final class SkuGenerator
{
    public function __construct(private readonly SkuCounter $counter, private readonly ProductRepository $products) {}

    public function next(string $prefix): string
    {
        $prefix = strtoupper($prefix);
        $digits = max(3, (int) config('catalog.sku_digits', 5));
        for ($i = 0; $i < 1000; $i++) {
            $sku = sprintf('%s-%0'.$digits.'d', $prefix, $this->counter->take($prefix));
            if (! $this->products->skuTaken($sku)) {
                return $sku;
            }
        }
        throw new \RuntimeException("Could not find a free SKU for [{$prefix}].");
    }
}
