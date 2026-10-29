<?php

declare(strict_types=1);

namespace Cronos\Orders\Domain\Events;

final readonly class OrderStatusChanged
{
    public function __construct(public string $orderId, public string $orderNumber, public string $from, public string $to, public bool $notifyCustomer) {}
}
