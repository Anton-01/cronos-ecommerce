<?php

declare(strict_types=1);

namespace Cronos\Shipping\Domain\Events;

final readonly class ShipmentCreated
{
    public function __construct(public string $shipmentId, public string $orderId, public string $orderNumber, public string $carrier, public ?string $trackingNumber, public ?string $trackingUrl, public bool $completesOrder) {}
}
