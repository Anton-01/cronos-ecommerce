<?php

declare(strict_types=1);

namespace Cronos\Media\Domain\ValueObjects;

/** One derived rendition of an image, e.g. a 160px square thumbnail. */
final readonly class Conversion
{
    public function __construct(
        public string $name,
        public int $width,
        public int $height,
        public string $fit,
        public string $format,
        public int $quality,
    ) {}
}
