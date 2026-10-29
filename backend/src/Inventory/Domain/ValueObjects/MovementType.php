<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\ValueObjects;

use Cronos\Inventory\Domain\Exceptions\InvalidMovement;

/** Why stock changed. The sign of a movement follows from its type (only adjustments go either way). */
final class MovementType
{
    public const PURCHASE = 'purchase';

    public const SALE = 'sale';

    public const RETURN = 'return';

    public const ADJUSTMENT = 'adjustment';

    public const WASTE = 'waste';

    public const TRANSFER_IN = 'transfer_in';

    public const TRANSFER_OUT = 'transfer_out';

    public const RESERVATION_CONSUME = 'reservation_consume';

    public const ENTRIES = [self::PURCHASE, self::RETURN, self::TRANSFER_IN];

    public const EXITS = [self::SALE, self::WASTE, self::TRANSFER_OUT, self::RESERVATION_CONSUME];

    public const ALL = [self::PURCHASE, self::SALE, self::RETURN, self::ADJUSTMENT, self::WASTE, self::TRANSFER_IN, self::TRANSFER_OUT, self::RESERVATION_CONSUME];

    /** Types a person may record by hand; the rest come from orders, reservations and transfers. */
    public const MANUAL = [self::PURCHASE, self::RETURN, self::ADJUSTMENT, self::WASTE];

    public static function assertKnown(string $type): void
    {
        in_array($type, self::ALL, true) || throw new InvalidMovement("Unknown movement type [{$type}].", 'UNKNOWN_MOVEMENT_TYPE');
    }

    /** +1 enters, -1 leaves, null = the caller decides (adjustments). */
    public static function direction(string $type): ?int
    {
        return match (true) {
            in_array($type, self::ENTRIES, true) => 1,
            in_array($type, self::EXITS, true) => -1,
            default => null,
        };
    }
}
