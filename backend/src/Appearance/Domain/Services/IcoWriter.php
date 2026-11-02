<?php

declare(strict_types=1);

namespace Cronos\Appearance\Domain\Services;

/** Builds a favicon.ico out of PNG images (the format every browser has accepted since Vista), without any image library. */
final class IcoWriter
{
    /** @param array<int, string> $pngBySize size (px, max 256) => PNG bytes */
    public static function build(array $pngBySize): string
    {
        ksort($pngBySize);
        $count = count($pngBySize);
        $header = pack('vvv', 0, 1, $count);
        $entries = '';
        $images = '';
        $offset = 6 + 16 * $count;
        foreach ($pngBySize as $size => $png) {
            $dim = $size >= 256 ? 0 : $size;
            $entries .= pack('CCCCvvVV', $dim, $dim, 0, 0, 1, 32, strlen($png), $offset);
            $images .= $png;
            $offset += strlen($png);
        }

        return $header.$entries.$images;
    }
}
