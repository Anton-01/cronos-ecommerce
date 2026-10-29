<?php

declare(strict_types=1);

namespace Cronos\Promotions\Domain\ValueObjects;

use Cronos\Pricing\Domain\ValueObjects\Money;

final class DiscountResult
{
    /**
     * @param  array<string, Money>  $lineDiscounts  line id => discount
     * @param  list<array{kind: 'promotion'|'coupon', id: string, name: string, amount: Money}>  $applied
     */
    public function __construct(
        public readonly Money $subtotal,
        public readonly array $lineDiscounts,
        public readonly Money $shippingDiscount,
        public readonly array $applied,
    ) {}

    public function itemsDiscount(): Money
    {
        return array_reduce($this->lineDiscounts, static fn (Money $c, Money $d): Money => $c->plus($d), Money::zero($this->subtotal->currency, $this->subtotal->decimals));
    }

    public function totalDiscount(): Money
    {
        return $this->itemsDiscount()->plus($this->shippingDiscount);
    }
}
