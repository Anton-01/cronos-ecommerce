<?php

declare(strict_types=1);

namespace Cronos\Media\Infrastructure\Adapters;

use Cronos\Media\Domain\Exceptions\InvalidImage;
use Cronos\Media\Domain\Ports\ImageProcessor;
use Cronos\Media\Domain\ValueObjects\Conversion;
use Cronos\Media\Domain\ValueObjects\ImageInfo;
use Cronos\Media\Domain\ValueObjects\RenderedImage;
use Cronos\Media\Domain\ValueObjects\Transform;

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
            // Centre the crop on the focal point (0..1), then keep it inside the picture.
            $cx = max(0, min($sw - $cw, (int) round($conversion->focalX * $sw - $cw / 2)));
            $cy = max(0, min($sh - $ch, (int) round($conversion->focalY * $sh - $ch / 2)));
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

        return new RenderedImage($this->encode($target, $conversion->format, $conversion->quality, $conversion->lossless), $dw, $dh);
    }

    public function transform(string $contents, Transform $t): RenderedImage
    {
        $source = @imagecreatefromstring($contents);
        $source !== false || throw new InvalidImage;
        $source = $this->applyOrientation($source, $contents);
        $sw = imagesx($source);
        $sh = imagesy($source);

        // The rectangle must lie inside the picture: a crop from a stale editor is clamped, never trusted.
        $x = max(0, min($sw - 1, $t->x));
        $y = max(0, min($sh - 1, $t->y));
        $w = max(1, min($sw - $x, $t->width));
        $h = max(1, min($sh - $y, $t->height));
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, (int) imagecolorallocatealpha($img, 255, 255, 255, 127));
        imagecopy($img, $source, 0, 0, $x, $y, $w, $h);

        if ($t->rotate % 360 !== 0) {
            // imagerotate turns counter-clockwise; the editor speaks clockwise.
            $img = imagerotate($img, -($t->rotate % 360), (int) imagecolorallocatealpha($img, 255, 255, 255, 127)) ?: $img;
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }
        $t->flipX && imageflip($img, IMG_FLIP_HORIZONTAL);
        $t->flipY && imageflip($img, IMG_FLIP_VERTICAL);

        if ($t->trim) {
            $cropped = imagecropauto($img, IMG_CROP_TRANSPARENT);
            $cropped === false || $img = $cropped;
        }

        $iw = imagesx($img);
        $ih = imagesy($img);
        $scale = min(1, ($t->maxWidth ?? $iw) / $iw, ($t->maxHeight ?? $ih) / $ih);
        if ($scale < 1) {
            $nw = max(1, (int) round($iw * $scale));
            $nh = max(1, (int) round($ih * $scale));
            $out = imagecreatetruecolor($nw, $nh);
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, (int) imagecolorallocatealpha($out, 255, 255, 255, 127));
            imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $iw, $ih);
            $img = $out;
        }

        return new RenderedImage($this->encode($img, $t->format, $t->quality, false), imagesx($img), imagesy($img));
    }

    public function hasAlpha(string $contents): bool
    {
        // PNG colour type 4/6 (or a tRNS chunk) and WebP/GIF with transparency; JPEG never has it.
        if (str_starts_with($contents, "\x89PNG")) {
            $type = ord($contents[25] ?? "\0");

            return $type === 4 || $type === 6 || str_contains(substr($contents, 0, 4096), 'tRNS');
        }
        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            return false;
        }
        // Sample on a grid: a transparent corner or margin is enough to say "this graphic needs its alpha".
        $w = imagesx($image);
        $h = imagesy($image);
        for ($i = 0; $i < 12; $i++) {
            for ($j = 0; $j < 12; $j++) {
                if (((imagecolorat($image, (int) (($w - 1) * $i / 11), (int) (($h - 1) * $j / 11)) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    public function canEncode(string $format): bool
    {
        return match ($format) {
            'webp' => function_exists('imagewebp'),
            'avif' => function_exists('imageavif') && (bool) (gd_info()['AVIF Support'] ?? false),
            'jpg', 'jpeg' => function_exists('imagejpeg'),
            'png' => function_exists('imagepng'),
            default => false,
        };
    }

    private function encode(\GdImage $image, string $format, int $quality, bool $lossless): string
    {
        ob_start();
        match ($format) {
            // IMG_WEBP_LOSSLESS (101): exact pixels for flat graphics and logos, where lossy would smear edges.
            'webp' => imagewebp($image, null, $lossless ? IMG_WEBP_LOSSLESS : $quality),
            'avif' => imageavif($image, null, $quality),
            'jpg', 'jpeg' => imagejpeg($this->onWhite($image), null, $quality),
            'png' => imagepng($image, null, 9),
            default => throw new \InvalidArgumentException("Unsupported conversion format [{$format}]."),
        };

        return (string) ob_get_clean();
    }

    /** JPEG has no alpha: transparent areas become white instead of black. */
    private function onWhite(\GdImage $image): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $image, 0, 0, 0, 0, $w, $h);

        return $flat;
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
