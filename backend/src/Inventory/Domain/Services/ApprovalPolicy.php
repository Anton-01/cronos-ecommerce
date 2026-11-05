<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * When does a shelf correction need someone else's yes? Only when it is big: more units than the limit, or a bigger share of what the
 * shelf held, or worth more money. A limit of 0 means "not used". If every limit is 0 nothing needs approval... except that this
 * would let anyone change anything, so the caller keeps at least one limit active (see WarehousePolicies).
 */
final class ApprovalPolicy
{
    /**
     * @param  array{approval_units: int, approval_percent: int, approval_amount: int}  $policy
     * @param  ?BigDecimal  $unitValue  what one unit is worth (null = unknown: a money limit then cannot clear it)
     */
    public static function needsApproval(BigDecimal $delta, BigDecimal $seenQuantity, ?BigDecimal $unitValue, array $policy): bool
    {
        $size = $delta->abs();
        if ($policy['approval_units'] > 0 && $size->isGreaterThan($policy['approval_units'])) {
            return true;
        }
        if ($policy['approval_percent'] > 0) {
            // Against an empty shelf any change is "infinite": it counts as over the limit.
            if ($seenQuantity->isLessThanOrEqualTo(0) || $size->multipliedBy(100)->dividedBy($seenQuantity, 3, RoundingMode::HalfUp)->isGreaterThan($policy['approval_percent'])) {
                return true;
            }
        }
        if ($policy['approval_amount'] > 0) {
            if ($unitValue === null || $size->multipliedBy($unitValue)->isGreaterThan($policy['approval_amount'])) {
                return true;
            }
        }

        return false;
    }
}
