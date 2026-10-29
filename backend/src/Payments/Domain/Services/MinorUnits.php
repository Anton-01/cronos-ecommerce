<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Cronos\Pricing\Domain\ValueObjects\Money;

/** Gateways speak integers of the smallest unit (cents). Exact conversion both ways, no floats. */
final class MinorUnits
{
    public static function of(Money $money): int
    {
        return $money->amount->multipliedBy(BigDecimal::of(10)->power($money->decimals))->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    public static function toAmount(int|string $minor, int $decimals = 2): string
    {
        return (string) BigDecimal::of((string) $minor)->dividedBy(BigDecimal::of(10)->power($decimals), $decimals, RoundingMode::HalfUp);
    }
}
