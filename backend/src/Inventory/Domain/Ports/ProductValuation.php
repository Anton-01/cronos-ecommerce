<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Ports;

interface ProductValuation
{
    /** What one unit cost us last time (from the Kardex), in the store's main currency; null when never bought. */
    public function unitValue(string $productId): ?string;
}
