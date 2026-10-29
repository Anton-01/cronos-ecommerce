<?php

declare(strict_types=1);

namespace Cronos\Shipping\Domain\Events;

final readonly class ShipmentDelivered
{
    public function __construct(public string $shipmentId, public string $orderId) {}
}
