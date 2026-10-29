<?php

declare(strict_types=1);

namespace Cronos\Shipping\Domain\Services;

/** Carrier events arrive late and out of order: a shipment only moves forward, so "in transit" after "delivered" changes nothing. */
final class ShipmentStatus
{
    public const ALL = ['pending', 'in_transit', 'exception', 'out_for_delivery', 'delivered', 'returned', 'cancelled'];

    private const RANK = ['pending' => 0, 'in_transit' => 1, 'exception' => 2, 'out_for_delivery' => 3, 'delivered' => 4, 'returned' => 5, 'cancelled' => 6];

    public static function isValid(string $status): bool
    {
        return isset(self::RANK[$status]);
    }

    public static function advances(string $from, string $to): bool
    {
        return self::RANK[$to] > self::RANK[$from] && $from !== 'cancelled';
    }
}
