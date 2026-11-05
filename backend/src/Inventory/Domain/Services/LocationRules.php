<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Services;

use Cronos\Inventory\Domain\Exceptions\InvalidMovement;

/** What makes a warehouse location well formed. Pure: no database. */
final class LocationRules
{
    public const TYPES = ['zone', 'aisle', 'shelf', 'level', 'bin', 'receiving', 'other'];

    /** The place every warehouse has for what arrived and has not been put away yet. Its code is reserved. */
    public const RECEIVING_CODE = 'RECEPCION';

    public static function code(string $raw): string
    {
        $code = strtoupper(trim($raw));
        preg_match('/^[A-Z0-9][A-Z0-9\-]{0,23}$/', $code) === 1 || throw new InvalidMovement('A location code is 1-24 letters, digits or dashes (e.g. A-03-2).', 'INVALID_LOCATION_CODE');

        return $code;
    }

    public static function name(string $raw): string
    {
        $name = trim($raw);
        ($name !== '' && mb_strlen($name) <= 120) || throw new InvalidMovement('A location needs a name (up to 120 characters).', 'INVALID_LOCATION_NAME');

        return $name;
    }

    public static function type(string $raw): string
    {
        in_array($raw, self::TYPES, true) || throw new InvalidMovement('Unknown location type.', 'INVALID_LOCATION_TYPE');

        return $raw;
    }

    public static function capacity(string|int|float|null $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        (is_numeric((string) $raw) && (float) $raw > 0) || throw new InvalidMovement('The capacity must be a number above zero.', 'INVALID_CAPACITY');

        return Quantities::positive($raw)->__toString();
    }

    /** A node may sit at most this deep (1 = a root). The subtree being moved counts too. */
    public static function assertDepth(int $parentDepth, int $subtreeHeight, int $max): void
    {
        $parentDepth + $subtreeHeight <= $max || throw new InvalidMovement("The warehouse tree can be at most {$max} levels deep.", 'LOCATION_TOO_DEEP');
    }
}
