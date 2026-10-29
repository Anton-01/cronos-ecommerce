<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Events;

final readonly class StockLow
{
    public function __construct(public string $productId, public ?string $sku, public string $available, public string $threshold) {}
}
