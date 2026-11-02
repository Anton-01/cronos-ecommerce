<?php

declare(strict_types=1);

namespace Cronos\Media\Domain\ValueObjects;

/** One derived rendition of an image, e.g. a 160px square thumbnail. `focal` (0..1) steers where a cover crop is centred. */
final readonly class Conversion
{
    public function __construct(
        public string $name,
        public int $width,
        public int $height,
        public string $fit,
        public string $format,
        public int $quality,
        public float $focalX = 0.5,
        public float $focalY = 0.5,
        public bool $lossless = false,
    ) {}
}
