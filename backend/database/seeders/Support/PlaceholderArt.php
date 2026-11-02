<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use GdImage;

/**
 * Neutral, original artwork drawn with GD (flowing satin bands, a spool, a bow): stands in for photography until the real photos exist.
 * Nothing here is a photo or a third party's picture; it is development data and is replaced from the back office.
 */
final class PlaceholderArt
{
    /** @var list<array{0: string, 1: string, 2: list<string>}> background top, background bottom, ribbon colours */
    private const PALETTES = [
        ['#FBF3EA', '#F3E2CF', ['#F59A57', '#E27F3B', '#FFC48F', '#FFFFFF']],
        ['#EEF3F7', '#DCE7EF', ['#5F8FB5', '#3E6E96', '#A9C7DD', '#FFFFFF']],
        ['#F7EEF0', '#EFDADF', ['#C8285A', '#A01C46', '#E98AA8', '#FFFFFF']],
    ];

    /** @return string path of a PNG */
    public static function hero(int $width, int $height, int $variant): string
    {
        $img = self::canvas($width, $height, $variant);
        [, , $colors] = self::PALETTES[$variant % 3];
        $h = $height;
        // Bands sweep in from the right so the left third (where the headline sits) stays calm.
        $sweeps = [[0.34, 0.95, 0.16, 0.1, 0.0, 0.07, 0], [0.38, 1.05, 0.12, 0.25, 0.08, 0.04, 1], [0.45, 1.15, 0.1, 0.0, 0.18, 0.025, 2], [0.4, 0.85, 0.2, 0.2, 0.0, 0.012, 3]];
        foreach ($sweeps as [$x0, $y0, $y1, $y2, $dy, $thick, $c]) {
            self::band($img, [[$width * $x0, $h * $y0], [$width * 0.62, $h * ($y1 + $dy)], [$width * 0.78, $h * ($y2 + 0.9)], [$width * 1.04, $h * ($y0 - 0.55 + $dy)]], $h * $thick, $colors[$c], $c === 3 ? 80 : 25);
        }

        return self::save($img, "hero-{$width}x{$height}-{$variant}");
    }

    public static function product(int $variant): string
    {
        $size = 2000;
        $img = imagecreatetruecolor($size, $size);
        imagefill($img, 0, 0, self::color($img, '#FFFFFF'));
        imageantialias($img, true);
        [, , $colors] = self::PALETTES[$variant % 3];
        // A spool: stacked discs with a hole, the ribbon end curling off the side.
        $cx = (int) ($size * 0.5);
        $cy = (int) ($size * 0.52);
        foreach ([[1.0, 0], [0.86, 1], [0.7, 0]] as [$scale, $c]) {
            imagefilledellipse($img, $cx, $cy, (int) ($size * 0.62 * $scale), (int) ($size * 0.62 * $scale), self::color($img, $colors[$c]));
        }
        imagefilledellipse($img, $cx, $cy, (int) ($size * 0.16), (int) ($size * 0.16), self::color($img, '#FFFFFF'));
        imagefilledellipse($img, $cx, $cy, (int) ($size * 0.1), (int) ($size * 0.1), self::color($img, '#EDEDED'));
        self::band($img, [[$cx + $size * 0.28, $cy + $size * 0.1], [$size * 0.92, $size * 0.45], [$size * 0.7, $size * 0.95], [$size * 0.88, $size * 0.9]], $size * 0.05, $colors[0], 0);
        self::shadow($img, $cx, (int) ($size * 0.88), (int) ($size * 0.5));

        return self::save($img, "product-{$variant}");
    }

    public static function bow(int $variant): string
    {
        $size = 2000;
        $img = imagecreatetruecolor($size, $size);
        imagefill($img, 0, 0, self::color($img, '#FFFFFF'));
        imageantialias($img, true);
        [, , $colors] = self::PALETTES[$variant % 3];
        $cx = $size / 2;
        $cy = $size * 0.46;
        // Two lobes, two tails and a knot.
        foreach ([-1, 1] as $side) {
            self::band($img, [[$cx, $cy], [$cx + $side * $size * 0.1, $cy + $size * 0.22], [$cx + $side * $size * 0.3, $cy + $size * 0.32], [$cx + $side * $size * 0.36, $cy + $size * 0.5]], $size * 0.09, $colors[1], 0);
            self::loop($img, $cx + $side * $size * 0.2, $cy - $size * 0.02, $size * 0.19, $size * 0.12, $side * -0.35, $size * 0.075, $colors[0]);
            self::loop($img, $cx + $side * $size * 0.2, $cy - $size * 0.02, $size * 0.115, $size * 0.055, $side * -0.35, $size * 0.035, $colors[2]);
        }
        imagefilledellipse($img, (int) $cx, (int) $cy, (int) ($size * 0.15), (int) ($size * 0.17), self::color($img, $colors[1]));
        self::shadow($img, (int) $cx, (int) ($size * 0.9), (int) ($size * 0.45));

        return self::save($img, "bow-{$variant}");
    }

    public static function category(int $variant): string
    {
        $size = 1200;
        $img = self::canvas($size, $size, $variant);
        [, , $colors] = self::PALETTES[$variant % 3];
        self::band($img, [[$size * 0.1, $size * 0.7], [$size * 0.4, $size * 0.1], [$size * 0.6, $size * 1.0], [$size * 0.92, $size * 0.35]], $size * 0.11, $colors[0], 15);
        self::band($img, [[$size * 0.08, $size * 0.5], [$size * 0.35, $size * 0.9], [$size * 0.65, $size * 0.1], [$size * 0.95, $size * 0.6]], $size * 0.05, $colors[2], 40);

        return self::save($img, "category-{$variant}");
    }

    private static function canvas(int $w, int $h, int $variant): GdImage
    {
        $img = imagecreatetruecolor($w, $h);
        imageantialias($img, true);
        [$top, $bottom] = self::PALETTES[$variant % 3];
        [$r1, $g1, $b1] = sscanf($top, '#%02x%02x%02x');
        [$r2, $g2, $b2] = sscanf($bottom, '#%02x%02x%02x');
        for ($y = 0; $y < $h; $y++) {
            $t = $y / max(1, $h - 1);
            imageline($img, 0, $y, $w, $y, (int) imagecolorallocate($img, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t)));
        }

        return $img;
    }

    /** A cubic Bézier drawn as a ribbon of constant width. @param list<array{0: float, 1: float}> $p */
    private static function band(GdImage $img, array $p, float $thickness, string $hex, int $alpha, bool $taper = true): void
    {
        $path = [];
        for ($i = 0; $i <= 160; $i++) {
            $t = $i / 160;
            $u = 1 - $t;
            $path[] = [$u ** 3 * $p[0][0] + 3 * $u * $u * $t * $p[1][0] + 3 * $u * $t * $t * $p[2][0] + $t ** 3 * $p[3][0], $u ** 3 * $p[0][1] + 3 * $u * $u * $t * $p[1][1] + 3 * $u * $t * $t * $p[2][1] + $t ** 3 * $p[3][1]];
        }
        self::stroke($img, $path, $thickness, $hex, $alpha, $taper);
    }

    /** A closed loop (ellipse rotated by $angle) drawn as a ribbon: the lobes of a bow. */
    private static function loop(GdImage $img, float $cx, float $cy, float $rx, float $ry, float $angle, float $thickness, string $hex): void
    {
        $path = [];
        for ($i = 0; $i <= 160; $i++) {
            $a = 2 * M_PI * $i / 160;
            $x = $rx * cos($a);
            $y = $ry * sin($a);
            $path[] = [$cx + $x * cos($angle) - $y * sin($angle), $cy + $x * sin($angle) + $y * cos($angle)];
        }
        self::stroke($img, $path, $thickness, $hex, 0, false);
    }

    /** Offsets the centre line to both sides and fills what lies between: left side forward, right side back. @param list<array{0: float, 1: float}> $path */
    private static function stroke(GdImage $img, array $path, float $thickness, string $hex, int $alpha, bool $taper): void
    {
        $n = count($path);
        $left = [];
        $right = [];
        foreach ($path as $i => [$x, $y]) {
            [$px, $py] = $path[max(0, $i - 1)];
            [$nx, $ny] = $path[min($n - 1, $i + 1)];
            $len = max(0.0001, hypot($nx - $px, $ny - $py));
            $w = $thickness * ($taper ? 0.45 + 0.55 * sin(M_PI * $i / ($n - 1)) : 1.0) / 2;
            $ox = -($ny - $py) / $len * $w;
            $oy = ($nx - $px) / $len * $w;
            $left[] = [$x + $ox, $y + $oy];
            $right[] = [$x - $ox, $y - $oy];
        }
        $flat = [];
        foreach ([...$left, ...array_reverse($right)] as [$x, $y]) {
            $flat[] = (int) round($x);
            $flat[] = (int) round($y);
        }
        imagefilledpolygon($img, $flat, self::color($img, $hex, $alpha));
    }

    private static function shadow(GdImage $img, int $cx, int $cy, int $width): void
    {
        for ($i = 6; $i >= 1; $i--) {
            imagefilledellipse($img, $cx, $cy, (int) ($width * (1 + $i * 0.08)), (int) ($width * 0.07 * (1 + $i * 0.1)), self::color($img, '#000000', 112 + 2 * (6 - $i)));
        }
    }

    private static function color(GdImage $img, string $hex, int $alpha = 0): int
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        return (int) imagecolorallocatealpha($img, $r, $g, $b, max(0, min(127, $alpha)));
    }

    private static function save(GdImage $img, string $name): string
    {
        $path = sys_get_temp_dir()."/cronos-art-{$name}.png";
        imagepng($img, $path, 6);

        return $path;
    }
}
