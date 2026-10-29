<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\Events;

/** Relayed from the outbox after the payment change committed; listeners must be idempotent (the relay delivers at least once). */
final readonly class PaymentRefunded
{
    public function __construct(public string $paymentId, public string $payableType, public string $payableId, public string $refundedTotal, public bool $full) {}
}
