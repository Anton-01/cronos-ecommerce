<?php

declare(strict_types=1);

namespace Cronos\Media\Domain\ValueObjects;

final readonly class ImageInfo
{
    public function __construct(public int $width, public int $height) {}

    public function pixels(): int
    {
        return $this->width * $this->height;
    }
}
