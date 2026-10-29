<?php

declare(strict_types=1);

namespace Cronos\Promotions\Domain\ValueObjects;

use DateTimeImmutable;

final class CouponRule
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly string $type, // fixed | percentage | free_shipping
        public readonly string $value,
        public readonly ?string $currency,
        public readonly ?string $minSpend,
        public readonly ?string $maxDiscount,
        public readonly ?int $maxUses,
        public readonly ?int $maxUsesPerUser,
        public readonly int $usesCount,
        public readonly bool $isStackable,
        public readonly bool $isActive,
        public readonly ?DateTimeImmutable $startsAt,
        public readonly ?DateTimeImmutable $expiresAt,
        public readonly Restrictions $restrictions,
    ) {}
}
