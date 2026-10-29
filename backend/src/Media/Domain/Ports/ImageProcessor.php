<?php

declare(strict_types=1);

namespace Cronos\Media\Domain\Ports;

use Cronos\Media\Domain\ValueObjects\Conversion;
use Cronos\Media\Domain\ValueObjects\ImageInfo;
use Cronos\Media\Domain\ValueObjects\RenderedImage;

interface ImageProcessor
{
    /** Reads only the header: safe to call before decoding untrusted data. Null when not an image. */
    public function inspect(string $contents): ?ImageInfo;

    /** Safe, privacy-relevant metadata (camera, capture date, orientation). Never GPS. @return array<string, scalar> */
    public function metadata(string $contents): array;

    public function convert(string $contents, Conversion $conversion): RenderedImage;
}
