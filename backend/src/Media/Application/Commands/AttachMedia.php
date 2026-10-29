<?php

declare(strict_types=1);

namespace Cronos\Media\Application\Commands;

final readonly class AttachMedia
{
    public function __construct(
        public string $id,
        public string $ownerType,
        public string $ownerId,
        public string $collection,
    ) {}
}
