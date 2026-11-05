<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Events;

/** A location's stock moved. Alerts and live views listen to this; listeners must be idempotent. */
final readonly class LocationStockChanged
{
    public function __construct(public string $productId, public int $warehouseId, public string $locationId) {}
}
