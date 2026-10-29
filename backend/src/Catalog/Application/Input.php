<?php

declare(strict_types=1);

namespace Cronos\Catalog\Application;

use Cronos\Catalog\Domain\Exceptions\InvalidProduct;
use Cronos\Catalog\Domain\ValueObjects\SalesUnit;

/** Strict parsing of numeric/structured input. Everything stays a decimal string: no float ever touches money or lengths. */
final class Input
{
    /** @return numeric-string|null */
    public static function money(mixed $value, string $field = 'price'): ?string
    {
        return self::decimal($value, $field, 12, 2);
    }

    /** @return numeric-string|null */
    public static function weight(mixed $value): ?string
    {
        return self::decimal($value, 'weight', 7, 3);
    }

    /** @return numeric-string|null */
    public static function quantity(mixed $value, string $field): ?string
    {
        return self::decimal($value, $field, 9, 3);
    }

    private static function decimal(mixed $value, string $field, int $int, int $frac): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = is_float($value) ? rtrim(rtrim(sprintf('%.'.$frac.'F', $value), '0'), '.') : (string) $value;

        (bool) preg_match('/^\d{1,'.$int.'}(\.\d{1,'.$frac.'})?$/', $text) || throw new InvalidProduct("The {$field} must be a non-negative number with at most {$frac} decimals.");

        return $text;
    }

    /** @param array<string, mixed>|null $dimensions @return array<string, mixed>|null */
    public static function dimensions(?array $dimensions): ?array
    {
        if ($dimensions === null || $dimensions === []) {
            return null;
        }

        $unit = $dimensions['unit'] ?? 'cm';
        in_array($unit, ['mm', 'cm', 'm'], true) || throw new InvalidProduct('The dimension unit must be mm, cm or m.');
        $out = ['unit' => $unit];
        foreach (['length', 'width', 'height'] as $side) {
            $out[$side] = self::decimal($dimensions[$side] ?? null, $side, 6, 2);
        }

        return $out;
    }

    /** @param array<string, mixed>|null $sales */
    public static function salesUnit(?array $sales): SalesUnit
    {
        if ($sales === null) {
            return SalesUnit::pieces();
        }

        return new SalesUnit(
            $sales['unit'] ?? SalesUnit::PIECE,
            self::quantity($sales['step'] ?? 1, 'step') ?? '1',
            self::quantity($sales['min'] ?? 1, 'minimum') ?? '1',
            self::quantity($sales['max'] ?? null, 'maximum'),
            (bool) ($sales['sell_by_cut'] ?? true),
            (bool) ($sales['sell_by_roll'] ?? false),
            self::quantity($sales['roll_length'] ?? null, 'roll length'),
            self::money($sales['roll_price'] ?? null, 'roll price'),
        );
    }
}
