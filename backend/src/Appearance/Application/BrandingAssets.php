<?php

declare(strict_types=1);

namespace Cronos\Appearance\Application;

use Cronos\Appearance\Domain\Exceptions\ThemeError;
use Cronos\Appearance\Domain\Ports\SvgSanitizer;
use Cronos\Appearance\Domain\Ports\ThemeMedia;
use Cronos\Appearance\Domain\Ports\ThemeRepository;
use Cronos\Appearance\Domain\Services\IcoWriter;
use Cronos\Media\Domain\Ports\ImageProcessor;
use Cronos\Media\Domain\ValueObjects\Transform;

/**
 * Logos, favicon and the share image: cropped as the person chose, scaled to the sizes the storefront really draws and stored with a
 * content hash in the name, so they can be cached forever and a changed logo can never be served stale.
 */
final class BrandingAssets
{
    public const SLOTS = ['logo', 'logoDark', 'logoMobile', 'favicon', 'og'];

    private const LOGO_SLOTS = ['logo', 'logoDark', 'logoMobile'];

    private const FAVICON_SIZES = [16, 32, 48, 180, 192, 512];

    public function __construct(
        private readonly ThemeRepository $themes,
        private readonly ThemeService $service,
        private readonly ThemeMedia $media,
        private readonly ImageProcessor $images,
        private readonly SvgSanitizer $svg,
    ) {}

    /**
     * Builds the asset of a slot from a library picture and a crop, and puts it in the draft.
     *
     * @param  array{x?: int, y?: int, width?: int, height?: int, rotate?: int, flipX?: bool, flipY?: bool, trim?: bool}|null  $crop  in pixels of the original; null = the whole picture
     * @return array<string, mixed> the asset
     */
    public function fromMedia(string $slot, string $mediaId, ?array $crop, ?string $userId): array
    {
        $this->assertSlot($slot);
        $original = $this->media->original($mediaId) ?? throw new ThemeError('Esa imagen no existe en la biblioteca.', 'MEDIA_NOT_FOUND');
        $crop = $this->cleanCrop($crop, $original['width'], $original['height'], $slot);
        $t = fn (array $over = []) => new Transform(...($over + ['x' => $crop['x'], 'y' => $crop['y'], 'width' => $crop['width'], 'height' => $crop['height'], 'rotate' => $crop['rotate'], 'flipX' => $crop['flipX'], 'flipY' => $crop['flipY'], 'trim' => $crop['trim']]));

        $files = match (true) {
            in_array($slot, self::LOGO_SLOTS, true) => $this->logoFiles($original['contents'], $t),
            $slot === 'favicon' => $this->faviconFiles($original['contents'], $t, $crop),
            default => $this->ogFiles($original['contents'], $t),
        };

        return $this->save($slot, ['kind' => 'raster', 'mediaId' => $mediaId, 'crop' => $crop] + $files, $userId);
    }

    /** A vector logo: sanitised and stored as it is. */
    public function fromSvg(string $slot, string $svg, ?string $userId): array
    {
        in_array($slot, self::LOGO_SLOTS, true) || throw new ThemeError('Solo los logotipos pueden ser SVG.', 'SVG_NOT_ALLOWED');
        strlen($svg) <= 200_000 || throw new ThemeError('El SVG pesa más de 200 KB: simplifícalo.', 'SVG_TOO_LARGE');
        $clean = $this->svg->sanitize($svg);
        $hash = substr(sha1($clean), 0, 12);
        $path = "branding/{$slot}-{$hash}.svg";
        $url = $this->media->store($path, $clean);

        return $this->save($slot, ['kind' => 'svg', 'mediaId' => null, 'crop' => null, 'hash' => $hash, 'width' => null, 'height' => null, 'files' => [['url' => $url, 'path' => $path, 'format' => 'svg', 'width' => null, 'height' => null, 'density' => 1]]], $userId);
    }

    public function remove(string $slot, ?string $userId): void
    {
        $this->assertSlot($slot);
        $draft = $this->service->draft($userId);
        $assets = $draft['assets'];
        unset($assets[$slot]);
        $this->themes->updateDraft($draft['id'], ['assets' => $assets]);
    }

    /** @param array<string, mixed> $asset @return array<string, mixed> */
    private function save(string $slot, array $asset, ?string $userId): array
    {
        $draft = $this->service->draft($userId);
        $assets = $draft['assets'];
        $assets[$slot] = $asset + ['updatedAt' => gmdate('c')];
        $this->themes->updateDraft($draft['id'], ['assets' => $assets]);

        return $assets[$slot];
    }

    /** A logo is shown at 40–64 px tall: a 1× and a 2× file, both lossless and transparent, plus a PNG for e-mails. @return array<string, mixed> */
    private function logoFiles(string $contents, callable $t): array
    {
        $files = [];
        $hash = substr(sha1($contents.json_encode($t())), 0, 12);
        $first = null;
        foreach ([[64, 1], [128, 2]] as [$h, $density]) {
            $r = $this->images->transform($contents, $t(['maxHeight' => $h, 'maxWidth' => 1200, 'format' => 'webp', 'quality' => 100]));
            $first ??= $r;
            $path = "branding/logo-{$hash}-{$density}x.webp";
            $files[] = ['url' => $this->media->store($path, $r->contents), 'path' => $path, 'format' => 'webp', 'width' => $r->width, 'height' => $r->height, 'density' => $density];
        }
        $png = $this->images->transform($contents, $t(['maxHeight' => 128, 'maxWidth' => 1200, 'format' => 'png']));
        $path = "branding/logo-{$hash}.png";
        $files[] = ['url' => $this->media->store($path, $png->contents), 'path' => $path, 'format' => 'png', 'width' => $png->width, 'height' => $png->height, 'density' => 2];

        return ['hash' => $hash, 'width' => $first->width, 'height' => $first->height, 'files' => $files];
    }

    /** @param array<string, mixed> $crop @return array<string, mixed> */
    private function faviconFiles(string $contents, callable $t, array $crop): array
    {
        // The crop is forced square: an icon is square everywhere it is used.
        $side = min($crop['width'], $crop['height']);
        $hash = substr(sha1($contents.json_encode($crop)), 0, 12);
        $files = [];
        $pngs = [];
        foreach (self::FAVICON_SIZES as $size) {
            $base = $this->images->transform($contents, new Transform($crop['x'], $crop['y'], $side, $side, $crop['rotate'], $crop['flipX'], $crop['flipY'], maxWidth: $size, maxHeight: $size, format: 'png'));
            $pngs[$size] = $base->contents;
            $path = "branding/favicon-{$hash}-{$size}.png";
            $files[] = ['url' => $this->media->store($path, $base->contents), 'path' => $path, 'format' => 'png', 'width' => $base->width, 'height' => $base->height, 'density' => 1, 'size' => $size];
        }
        $ico = IcoWriter::build(array_intersect_key($pngs, array_flip([16, 32, 48])));
        $path = "branding/favicon-{$hash}.ico";
        $files[] = ['url' => $this->media->store($path, $ico), 'path' => $path, 'format' => 'ico', 'width' => 48, 'height' => 48, 'density' => 1, 'size' => 48];

        return ['hash' => $hash, 'width' => 512, 'height' => 512, 'files' => $files];
    }

    /** @return array<string, mixed> */
    private function ogFiles(string $contents, callable $t): array
    {
        $hash = substr(sha1($contents.json_encode($t())), 0, 12);
        $files = [];
        foreach ([['webp', 'webp', 82], ['jpg', 'jpg', 84]] as [$ext, $format, $q]) {
            $r = $this->images->transform($contents, $t(['maxWidth' => 1200, 'maxHeight' => 630, 'format' => $format, 'quality' => $q]));
            $path = "branding/og-{$hash}.{$ext}";
            $files[] = ['url' => $this->media->store($path, $r->contents), 'path' => $path, 'format' => $ext, 'width' => $r->width, 'height' => $r->height, 'density' => 1];
        }

        return ['hash' => $hash, 'width' => $files[0]['width'], 'height' => $files[0]['height'], 'files' => $files];
    }

    /** @param array<string, mixed>|null $crop @return array{x: int, y: int, width: int, height: int, rotate: int, flipX: bool, flipY: bool, trim: bool} */
    private function cleanCrop(?array $crop, int $w, int $h, string $slot): array
    {
        $crop ??= [];
        $rotate = (int) ($crop['rotate'] ?? 0);
        in_array($rotate % 360, [0, 90, 180, 270, -90, -180, -270], true) || throw new ThemeError('La rotación es en pasos de 90°.', 'INVALID_CROP');
        $x = (int) ($crop['x'] ?? 0);
        $y = (int) ($crop['y'] ?? 0);
        $cw = (int) ($crop['width'] ?? $w);
        $ch = (int) ($crop['height'] ?? $h);
        ($x >= 0 && $y >= 0 && $cw >= 1 && $ch >= 1 && $x + $cw <= $w && $y + $ch <= $h) || throw new ThemeError('El recorte se sale de la imagen.', 'INVALID_CROP');
        // A tiny crop of a big picture would be upscaled into mush: refuse instead of producing a blurry logo.
        $min = config("images.slots.{$slot}.min") ?? [1, 1];
        ($cw >= $min[0] && $ch >= $min[1]) || throw new ThemeError("El recorte mide {$cw}×{$ch} px y se necesita al menos {$min[0]}×{$min[1]} px.", 'CROP_TOO_SMALL');

        return ['x' => $x, 'y' => $y, 'width' => $cw, 'height' => $ch, 'rotate' => $rotate, 'flipX' => (bool) ($crop['flipX'] ?? false), 'flipY' => (bool) ($crop['flipY'] ?? false), 'trim' => (bool) ($crop['trim'] ?? false)];
    }

    private function assertSlot(string $slot): void
    {
        in_array($slot, self::SLOTS, true) || throw new ThemeError('Ese lugar de la identidad no existe.', 'UNKNOWN_SLOT');
    }
}
