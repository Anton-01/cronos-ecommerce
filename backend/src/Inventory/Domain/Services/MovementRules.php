<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Services;

use Brick\Math\BigDecimal;
use Cronos\Inventory\Domain\Exceptions\InsufficientStock;
use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Cronos\Inventory\Domain\ValueObjects\MovementType;

final class MovementRules
{
    /**
     * Turns the positive amount a person typed into the signed quantity stored in the Kardex.
     *
     * @param  ?bool  $increase  only for adjustments: true adds stock, false removes it
     */
    public function signed(string $type, BigDecimal $amount, ?bool $increase = null): BigDecimal
    {
        MovementType::assertKnown($type);
        $direction = MovementType::direction($type);

        if ($direction === null) {
            $increase !== null || throw new InvalidMovement('An adjustment must say whether it adds or removes stock.', 'DIRECTION_REQUIRED');
            $direction = $increase ? 1 : -1;
        } elseif ($increase !== null && ($increase ? 1 : -1) !== $direction) {
            throw new InvalidMovement("A {$type} movement cannot go that way.", 'DIRECTION_MISMATCH');
        }

        return $direction > 0 ? $amount : $amount->negated();
    }

    /**
     * Stock may only be taken from what is not reserved by someone else, unless the product accepts backorders.
     * Consuming a reservation takes from the reserved part itself, so only the on-hand total must cover it.
     */
    public function assertCanTake(BigDecimal $onHand, BigDecimal $reserved, BigDecimal $amount, bool $allowBackorder, bool $consumesReservation, ?string $sku = null): void
    {
        if ($allowBackorder) {
            return;
        }

        $covered = $consumesReservation ? $onHand->isGreaterThanOrEqualTo($amount) : $onHand->minus($reserved)->isGreaterThanOrEqualTo($amount);
        $covered || throw new InsufficientStock((string) $amount, (string) ($consumesReservation ? $onHand : $onHand->minus($reserved)), $sku);
    }
}
