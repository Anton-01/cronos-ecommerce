<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Events;

/** The stock figure of a product moved (movement, reservation or release): caches that show availability must refresh. */
final readonly class StockChanged
{
    public function __construct(public string $productId) {}
}
