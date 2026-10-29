<?php

declare(strict_types=1);

namespace Cronos\Promotions\Domain\ValueObjects;

use Cronos\Pricing\Domain\ValueObjects\Money;

final class Cart
{
    /** @param list<CartLine> $lines */
    public function __construct(
        public readonly string $market,
        public readonly string $currency,
        public readonly int $decimals,
        public readonly array $lines,
        public readonly Money $shipping,
        public readonly ?string $userId = null,
        public readonly bool $isFirstOrder = false,
    ) {}

    public function subtotal(): Money
    {
        return array_reduce($this->lines, static fn (Money $c, CartLine $l): Money => $c->plus($l->total), Money::zero($this->currency, $this->decimals));
    }
}
