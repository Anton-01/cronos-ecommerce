<?php

declare(strict_types=1);

namespace Cronos\Media\Domain\ValueObjects;

/** Encoded output of a conversion together with its real pixel size. */
final readonly class RenderedImage
{
    public function __construct(public string $contents, public int $width, public int $height) {}
}
