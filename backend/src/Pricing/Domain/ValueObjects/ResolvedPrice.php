<?php

declare(strict_types=1);

namespace Cronos\Pricing\Domain\ValueObjects;

use DateTimeImmutable;

/** The price a customer sees in one currency at one moment. */
final class ResolvedPrice
{
    public function __construct(
        public readonly Money $list,
        public readonly Money $effective,
        public readonly ?Money $compareAt,
        public readonly bool $onSale,
        public readonly ?Money $rollPrice,
        public readonly ?DateTimeImmutable $saleEndsAt,
    ) {}
}
