<?php

declare(strict_types=1);

namespace Cronos\Checkout\Domain\Events;

/** A checkout was paid in full and its stock sold. Phase 7 turns it into an order; listeners must be idempotent. */
final readonly class CheckoutPaid
{
    public function __construct(public string $checkoutId, public string $paymentId, public ?string $userId, public string $total, public string $currency) {}
}
