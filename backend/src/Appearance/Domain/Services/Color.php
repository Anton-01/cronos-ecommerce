<?php

declare(strict_types=1);

namespace Cronos\Appearance\Domain\Services;

use Cronos\Appearance\Domain\Exceptions\ThemeError;

/** Colour maths in plain PHP: parsing, WCAG contrast, mixing and the lighter/darker shades a theme derives from its main colours. */
final class Color
{
    /** @return array{0: int, 1: int, 2: int} */
    public static function rgb(string $hex): array
    {
        $h = ltrim(trim($hex), '#');
        if (strlen($h) === 3) {
            $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
        }
        preg_match('/^[0-9a-fA-F]{6}$/', $h) === 1 || throw new ThemeError("[{$hex}] is not a colour: use #RGB or #RRGGBB.", 'INVALID_COLOR');

        return [(int) hexdec(substr($h, 0, 2)), (int) hexdec(substr($h, 2, 2)), (int) hexdec(substr($h, 4, 2))];
    }

    public static function normalize(string $hex): string
    {
        return self::hex(self::rgb($hex));
    }

    /** @param array{0: int|float, 1: int|float, 2: int|float} $rgb */
    public static function hex(array $rgb): string
    {
        return sprintf('#%02X%02X%02X', max(0, min(255, (int) round($rgb[0]))), max(0, min(255, (int) round($rgb[1]))), max(0, min(255, (int) round($rgb[2]))));
    }

    /** WCAG relative luminance, 0 (black) to 1 (white). */
    public static function luminance(string $hex): float
    {
        $c = array_map(static function (int $v): float {
            $s = $v / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    /** WCAG contrast ratio between two colours, 1 to 21. AA needs 4.5 for normal text, 3 for large text. */
    public static function contrast(string $a, string $b): float
    {
        [$l1, $l2] = [self::luminance($a), self::luminance($b)];

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** White or near-black, whichever reads better on this background. */
    public static function readableOn(string $background): string
    {
        return self::contrast($background, '#FFFFFF') >= self::contrast($background, '#1A1A1A') ? '#FFFFFF' : '#1A1A1A';
    }

    /** $weight of $b mixed into $a (0 = a, 1 = b). */
    public static function mix(string $a, string $b, float $weight): string
    {
        [$x, $y] = [self::rgb($a), self::rgb($b)];

        return self::hex([$x[0] + ($y[0] - $x[0]) * $weight, $x[1] + ($y[1] - $x[1]) * $weight, $x[2] + ($y[2] - $x[2]) * $weight]);
    }

    /**
     * The colour closest to `$color` that still reads as TEXT (at least `$min`, WCAG AA = 4.5) on every given background: darkened on light
     * backgrounds, lightened on dark ones. A brand orange is a fine button but a poor price; this is the shade to print numbers in.
     *
     * @param  list<string>  $backgrounds
     */
    public static function inkOn(string $color, array $backgrounds, float $min = 4.5): string
    {
        $ok = static fn (string $c): bool => array_reduce($backgrounds, static fn (bool $all, string $bg) => $all && self::contrast($c, $bg) >= $min, true);
        $darkBackgrounds = array_sum(array_map(self::luminance(...), $backgrounds)) / max(1, count($backgrounds)) < 0.18;
        $candidate = self::normalize($color);
        for ($step = 0; $step <= 20 && ! $ok($candidate); $step++) {
            $candidate = $darkBackgrounds ? self::lighten($color, $step * 0.05) : self::darken($color, $step * 0.05);
        }

        return $candidate;
    }

    public static function darken(string $hex, float $amount): string
    {
        return self::mix($hex, '#000000', $amount);
    }

    public static function lighten(string $hex, float $amount): string
    {
        return self::mix($hex, '#FFFFFF', $amount);
    }

    /** "245 154 87": the form CSS needs to put an opacity on a colour (rgb(var(--x) / .5)). */
    public static function channels(string $hex): string
    {
        return implode(' ', self::rgb($hex));
    }
}
