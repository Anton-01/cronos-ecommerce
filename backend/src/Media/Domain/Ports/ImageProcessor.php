<?php

declare(strict_types=1);

namespace Cronos\Media\Domain\Ports;

use Cronos\Media\Domain\ValueObjects\Conversion;
use Cronos\Media\Domain\ValueObjects\ImageInfo;
use Cronos\Media\Domain\ValueObjects\RenderedImage;
use Cronos\Media\Domain\ValueObjects\Transform;

interface ImageProcessor
{
    /** Reads only the header: safe to call before decoding untrusted data. Null when not an image. */
    public function inspect(string $contents): ?ImageInfo;

    /** Safe, privacy-relevant metadata (camera, capture date, orientation). Never GPS. @return array<string, scalar> */
    public function metadata(string $contents): array;

    public function convert(string $contents, Conversion $conversion): RenderedImage;

    /** Applies a crop/rotate/flip/resize recipe to an image (used for logos, icons and hand-cropped pictures). */
    public function transform(string $contents, Transform $transform): RenderedImage;

    /** True when the picture has real transparency (decides lossless vs lossy encoding of graphics). */
    public function hasAlpha(string $contents): bool;

    /** Whether this server can encode the format ("webp", "avif", "jpg", "png"). */
    public function canEncode(string $format): bool;
}
