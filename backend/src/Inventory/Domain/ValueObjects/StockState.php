<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** ok → low → critical → out, and how a quantity moves between them without flapping at the edge. */
final class StockState
{
    public const OK = 'ok';

    public const LOW = 'low';

    public const CRITICAL = 'critical';

    public const OUT = 'out';

    private const RANK = [self::OK => 0, self::LOW => 1, self::CRITICAL => 2, self::OUT => 3];

    public static function rank(string $state): int
    {
        return self::RANK[$state];
    }

    /** What the quantity is, with the thresholds exactly as set. */
    public static function raw(BigDecimal $quantity, BigDecimal $minimum, ?BigDecimal $critical, ?BigDecimal $factor = null): string
    {
        $scale = static fn (BigDecimal $v): BigDecimal => $factor === null ? $v : $v->multipliedBy($factor);
        if ($quantity->isLessThanOrEqualTo(0)) {
            return self::OUT;
        }
        if ($critical !== null && $quantity->isLessThanOrEqualTo($scale($critical))) {
            return self::CRITICAL;
        }

        return $quantity->isLessThanOrEqualTo($scale($minimum)) ? self::LOW : self::OK;
    }

    /**
     * Getting worse is noticed at once; getting better only counts once the quantity clears the threshold by a margin
     * (e.g. 10 %), so a shelf hovering at the minimum does not send a message with every movement.
     *
     * @param  int  $marginPercent  recovery margin
     */
    public static function next(string $current, BigDecimal $quantity, BigDecimal $minimum, ?BigDecimal $critical, int $marginPercent): string
    {
        $now = self::raw($quantity, $minimum, $critical);
        if (self::rank($now) >= self::rank($current)) {
            return $now;
        }
        $factor = BigDecimal::of(100 + max(0, $marginPercent))->dividedBy(100, 6, RoundingMode::HalfUp);
        $cleared = self::raw($quantity, $minimum, $critical, $factor);

        return self::rank($cleared) < self::rank($current) ? $cleared : $current;
    }
}
