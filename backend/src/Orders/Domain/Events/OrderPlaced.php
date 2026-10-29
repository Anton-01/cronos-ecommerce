<?php

declare(strict_types=1);

namespace Cronos\Orders\Domain\Events;

/** A paid order exists. Notifications, analytics and fulfilment listen to this. Listeners must be idempotent. */
final readonly class OrderPlaced
{
    public function __construct(public string $orderId, public string $orderNumber, public ?string $userId, public string $email, public string $total, public string $currency) {}
}
