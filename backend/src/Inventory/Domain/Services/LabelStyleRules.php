<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Services;

use Cronos\Inventory\Domain\Exceptions\InvalidMovement;

/** What a QR label may look like. Pure: validates and completes a style so a bad value can never reach a printer. */
final class LabelStyleRules
{
    public const DEFAULTS = [
        'foreground' => '#111827',
        'background' => '#FFFFFF',
        'errorCorrection' => 'M',
        'margin' => 2,
        'widthMm' => 60,
        'heightMm' => 40,
        'qrSizeMm' => 28,
        'headerText' => '',
        'showName' => true,
        'showSku' => true,
        'showCode' => true,
    ];

    /** @param array<string, mixed> $input @param array<string, mixed> $base @return array<string, mixed> */
    public static function merge(array $input, array $base = self::DEFAULTS): array
    {
        $style = array_intersect_key($input, self::DEFAULTS) + $base;

        foreach (['foreground', 'background'] as $key) {
            (is_string($style[$key]) && preg_match('/^#[0-9A-Fa-f]{6}$/', $style[$key]) === 1) || throw new InvalidMovement("{$key} must be a color like #111827.", 'INVALID_LABEL_STYLE');
            $style[$key] = strtoupper($style[$key]);
        }
        in_array($style['errorCorrection'], ['L', 'M', 'Q', 'H'], true) || throw new InvalidMovement('Error correction is L, M, Q or H.', 'INVALID_LABEL_STYLE');
        self::within($style, 'margin', 0, 8);
        self::within($style, 'widthMm', 20, 210);
        self::within($style, 'heightMm', 15, 297);
        self::within($style, 'qrSizeMm', 10, 120);
        ($style['qrSizeMm'] <= min($style['widthMm'], $style['heightMm'])) || throw new InvalidMovement('The QR cannot be bigger than the label.', 'INVALID_LABEL_STYLE');
        (is_string($style['headerText']) && mb_strlen($style['headerText']) <= 40) || throw new InvalidMovement('The header is up to 40 characters.', 'INVALID_LABEL_STYLE');
        foreach (['showName', 'showSku', 'showCode'] as $flag) {
            $style[$flag] = (bool) $style[$flag];
        }
        // A label that hides the typed code leaves no fallback when a camera cannot read the QR.
        $style['showCode'] || $style['showSku'] || throw new InvalidMovement('Show at least the SKU or the code under the QR, so it can be typed when the camera fails.', 'INVALID_LABEL_STYLE');
        // The QR must stay readable: enough contrast between its two colors.
        self::contrast($style['foreground'], $style['background']) >= 4.0 || throw new InvalidMovement('The QR colors need more contrast to be readable from a shelf.', 'LOW_CONTRAST');

        return $style;
    }

    /** @param array<string, mixed> $style */
    private static function within(array &$style, string $key, int $min, int $max): void
    {
        (is_numeric($style[$key]) && (int) $style[$key] == $style[$key] && $style[$key] >= $min && $style[$key] <= $max) || throw new InvalidMovement("{$key} must be a whole number between {$min} and {$max}.", 'INVALID_LABEL_STYLE');
        $style[$key] = (int) $style[$key];
    }

    /** WCAG contrast ratio between two #RRGGBB colors. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $c = array_map(static function (string $h): float {
            $v = hexdec($h) / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }
}
