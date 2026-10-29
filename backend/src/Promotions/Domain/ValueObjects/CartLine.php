<?php

declare(strict_types=1);

namespace Cronos\Promotions\Domain\ValueObjects;

use Cronos\Pricing\Domain\ValueObjects\Money;

/** One priced line of a cart. `total` is what the line costs before discounts (quantity x price, roll prices included). */
final class CartLine
{
    /** @param list<string> $categoryIds */
    public function __construct(
        public readonly string $id,
        public readonly string $productId,
        public readonly array $categoryIds,
        public readonly string $quantity,
        public readonly Money $total,
        public readonly bool $onSale = false,
    ) {}
}
