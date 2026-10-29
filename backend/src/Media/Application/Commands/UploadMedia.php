<?php

declare(strict_types=1);

namespace Cronos\Media\Application\Commands;

final readonly class UploadMedia
{
    public function __construct(
        public string $path,
        public string $clientName,
        public string $collection,
        public ?string $uploadedBy,
        public ?array $onlyMimes = null,
        public ?int $maxKb = null,
    ) {}
}
