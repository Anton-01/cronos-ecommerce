<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\Events;

/** Relayed from the outbox after the payment change committed; listeners must be idempotent (the relay delivers at least once). */
final readonly class PaymentNeedsReview
{
    public function __construct(public string $paymentId, public string $payableType, public string $payableId, public string $reason) {}
}
