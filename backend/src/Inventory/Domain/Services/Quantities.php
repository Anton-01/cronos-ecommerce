<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Services;

use Brick\Math\BigDecimal;
use Cronos\Inventory\Domain\Exceptions\InvalidMovement;

/** Stock is decimal (metres of ribbon, pieces): three decimals, exact arithmetic, never a float. */
final class Quantities
{
    public const SCALE = 3;

    /** A positive amount with at most three decimals. Extra precision is refused rather than silently rounded away. */
    public static function positive(string|int|float|BigDecimal $value): BigDecimal
    {
        is_numeric((string) $value) || throw new InvalidMovement('The quantity must be a number.', 'INVALID_QUANTITY');
        $n = BigDecimal::of((string) $value);
        $n->isGreaterThan(0) || throw new InvalidMovement('The quantity must be greater than zero.', 'INVALID_QUANTITY');
        $n->getScale() <= self::SCALE || $n->strippedOfTrailingZeros()->getScale() <= self::SCALE || throw new InvalidMovement('Quantities have at most three decimals.', 'QUANTITY_PRECISION');

        return $n->toScale(self::SCALE);
    }

    public static function zero(): BigDecimal
    {
        return BigDecimal::zero()->toScale(self::SCALE);
    }

    public static function of(string|BigDecimal $value): BigDecimal
    {
        return BigDecimal::of((string) $value)->toScale(self::SCALE);
    }
}
