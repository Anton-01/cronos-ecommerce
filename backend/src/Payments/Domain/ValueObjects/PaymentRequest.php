<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\ValueObjects;

use Cronos\Pricing\Domain\ValueObjects\Money;

/** What the shop asks a gateway to collect. The idempotency key makes asking twice the same as asking once. */
final readonly class PaymentRequest
{
    /** @param array<string, scalar|null> $metadata */
    public function __construct(
        public string $provider,
        public string $payableType,
        public string $payableId,
        public Money $amount,
        public string $marketCode,
        public string $idempotencyKey,
        public ?string $customerEmail = null,
        public ?string $description = null,
        public ?string $returnUrl = null,
        public ?string $cancelUrl = null,
        public array $metadata = [],
    ) {}
}
