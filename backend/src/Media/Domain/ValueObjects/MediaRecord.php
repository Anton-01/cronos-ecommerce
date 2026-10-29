<?php

declare(strict_types=1);

namespace Cronos\Media\Domain\ValueObjects;

/** What the application layer needs to know about a stored media item. */
final readonly class MediaRecord
{
    public function __construct(
        public string $id,
        public string $disk,
        public string $path,
        public string $mimeType,
        public string $status,
        public ?int $width = null,
        public ?int $height = null,
    ) {}

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }
}
