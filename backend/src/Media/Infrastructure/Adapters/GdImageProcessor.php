<?php

declare(strict_types=1);

namespace Cronos\Media\Infrastructure\Adapters;

use Cronos\Media\Domain\Exceptions\InvalidImage;
use Cronos\Media\Domain\Ports\ImageProcessor;
use Cronos\Media\Domain\ValueObjects\Conversion;
use Cronos\Media\Domain\ValueObjects\ImageInfo;
use Cronos\Media\Domain\ValueObjects\RenderedImage;

/** Image handling on the GD extension (no third-party dependency). Output is re-encoded, which also strips EXIF/GPS. */
final class GdImageProcessor implements ImageProcessor
{
    public function inspect(string $contents): ?ImageInfo
    {
        $size = @getimagesizefromstring($contents);

        return $size === false || $size[0] < 1 || $size[1] < 1 ? null : new ImageInfo($size[0], $size[1]);
    }

    public function metadata(string $contents): array
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($contents, "\xFF\xD8")) {
            return [];
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $contents);
        rewind($stream);
        $exif = @exif_read_data($stream, 'IFD0,EXIF', false);
        fclose($stream);

        if (! is_array($exif)) {
            return [];
        }

        // Allow-list: privacy first (no GPS, no serial numbers, no software fingerprints).
        $wanted = ['Make' => 'camera_make', 'Model' => 'camera_model', 'DateTimeOriginal' => 'taken_at', 'Orientation' => 'orientation'];
        $out = [];

        foreach ($wanted as $source => $target) {
            if (isset($exif[$source]) && is_scalar($exif[$source])) {
                $out[$target] = is_string($exif[$source]) ? $this->cleanText($exif[$source]) : $exif[$source];
            }
        }

        return array_filter($out, fn ($v) => $v !== '');
    }

    public function convert(string $contents, Conversion $conversion): RenderedImage
    {
        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            throw new InvalidImage;
        }

        $source = $this->applyOrientation($source, $contents);
        $sw = imagesx($source);
        $sh = imagesy($source);

        if ($conversion->fit === 'cover') {
            $dw = $conversion->width;
            $dh = $conversion->height;
            $scale = max($dw / $sw, $dh / $sh);
            $cw = min($sw, (int) round($dw / $scale));
            $ch = min($sh, (int) round($dh / $scale));
            $cx = intdiv($sw - $cw, 2);
            $cy = intdiv($sh - $ch, 2);
        } else {
            // contain: keep the ratio and never upscale a small original.
            $scale = min($conversion->width / $sw, $conversion->height / $sh, 1);
            $dw = max(1, (int) round($sw * $scale));
            $dh = max(1, (int) round($sh * $scale));
            [$cx, $cy, $cw, $ch] = [0, 0, $sw, $sh];
        }

        $target = imagecreatetruecolor($dw, $dh);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 255, 255, 255, 127));
        imagecopyresampled($target, $source, 0, 0, $cx, $cy, $dw, $dh, $cw, $ch);

        ob_start();
        match ($conversion->format) {
            'webp' => imagewebp($target, null, $conversion->quality),
            'jpg', 'jpeg' => imagejpeg($target, null, $conversion->quality),
            'png' => imagepng($target),
            default => throw new \InvalidArgumentException("Unsupported conversion format [{$conversion->format}]."),
        };
        $encoded = (string) ob_get_clean();

        return new RenderedImage($encoded, $dw, $dh);
    }

    private function applyOrientation(\GdImage $image, string $contents): \GdImage
    {
        $orientation = (int) ($this->metadata($contents)['orientation'] ?? 1);
        $angle = match ($orientation) {
            3 => 180, 6 => -90, 8 => 90, default => 0
        };

        if ($angle === 0) {
            return $image;
        }

        return imagerotate($image, $angle, (int) imagecolorallocatealpha($image, 255, 255, 255, 127)) ?: $image;
    }

    private function cleanText(string $value): string
    {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        // jsonb rejects NUL and invalid sequences; keep printable text only.
        return trim((string) preg_replace('/[\p{C}]+/u', '', $value));
    }
}
