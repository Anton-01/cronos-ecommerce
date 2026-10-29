<?php

declare(strict_types=1);

namespace Cronos\Fraud\Domain\ValueObjects;

use Cronos\Pricing\Domain\ValueObjects\Money;

final class FraudContext
{
    public function __construct(
        public readonly ?string $ip,
        public readonly ?string $email,
        public readonly ?string $userId,
        public readonly Money $total,
        public readonly ?string $billingCountry,
        public readonly ?string $shippingCountry,
    ) {}
}
