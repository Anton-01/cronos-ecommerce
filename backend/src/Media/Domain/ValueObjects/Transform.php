<?php

declare(strict_types=1);

namespace Cronos\Media\Domain\ValueObjects;

/**
 * An edit chosen in the back office: a crop rectangle in pixels of the ORIGINAL (after the camera orientation is applied), then a
 * rotation in 90° steps and optional flips, then scaled down to fit the box (never up). The same recipe always gives the same file.
 */
final readonly class Transform
{
    public function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
        public int $rotate = 0,
        public bool $flipX = false,
        public bool $flipY = false,
        public ?int $maxWidth = null,
        public ?int $maxHeight = null,
        public string $format = 'png',
        public int $quality = 90,
        public bool $trim = false,
    ) {}
}
