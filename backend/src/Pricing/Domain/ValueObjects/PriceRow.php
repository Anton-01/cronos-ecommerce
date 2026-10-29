<?php

declare(strict_types=1);

namespace Cronos\Pricing\Domain\ValueObjects;

use Cronos\Pricing\Domain\Exceptions\InvalidPricing;
use DateTimeImmutable;

/** What a merchant sets for one product in one currency; every field is optional (null = derive). */
final class PriceRow
{
    public function __construct(
        public readonly ?string $price = null,
        public readonly ?string $rollPrice = null,
        public readonly ?string $compareAt = null,
        public readonly ?string $salePrice = null,
        public readonly ?DateTimeImmutable $saleStartsAt = null,
        public readonly ?DateTimeImmutable $saleEndsAt = null,
    ) {
        foreach ([$price, $rollPrice, $compareAt, $salePrice] as $amount) {
            $amount === null || (is_numeric($amount) && (float) $amount >= 0) || throw new InvalidPricing('Prices must be zero or more.');
        }
        ($saleStartsAt === null || $saleEndsAt === null || $saleStartsAt < $saleEndsAt) || throw new InvalidPricing('The sale must end after it starts.', 'INVALID_SALE_WINDOW');
        $salePrice === null || $price === null || (float) $salePrice < (float) $price || throw new InvalidPricing('The sale price must be lower than the regular price.', 'SALE_NOT_LOWER');
    }

    public function saleActiveAt(DateTimeImmutable $at): bool
    {
        return $this->salePrice !== null
            && ($this->saleStartsAt === null || $this->saleStartsAt <= $at)
            && ($this->saleEndsAt === null || $at < $this->saleEndsAt);
    }
}
