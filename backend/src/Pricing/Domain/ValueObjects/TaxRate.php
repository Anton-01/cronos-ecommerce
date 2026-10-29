<?php

declare(strict_types=1);

namespace Cronos\Pricing\Domain\ValueObjects;

use Brick\Math\BigDecimal;
use Cronos\Pricing\Domain\Exceptions\InvalidPricing;

final class TaxRate
{
    public readonly BigDecimal $rate;

    /** @param string|int $rate percent, e.g. "16" for Mexican IVA */
    public function __construct(
        public readonly string $name,
        string|int $rate,
        public readonly int $priority = 0,
        public readonly bool $isCompound = false,
    ) {
        $this->rate = BigDecimal::of($rate);
        ($this->rate->isGreaterThanOrEqualTo(0) && $this->rate->isLessThanOrEqualTo(100)) || throw new InvalidPricing('A tax rate must be between 0 and 100 %.');
    }
}
