<?php

declare(strict_types=1);

namespace Cronos\Media\Domain\ValueObjects;

final readonly class NewMedia
{
    public function __construct(
        public string $id,
        public string $name,
        public string $fileName,
        public string $mimeType,
        public string $disk,
        public int $size,
        public string $checksum,
        public string $collection,
        public ?string $uploadedBy,
        public ?int $width,
        public ?int $height,
    ) {}
}
