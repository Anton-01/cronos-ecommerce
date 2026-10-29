<?php

declare(strict_types=1);

namespace Cronos\Media\Application\Commands;

final readonly class ReorderMedia
{
    public function __construct(
        public string $ownerType,
        public string $ownerId,
        public string $collection,
        public array $orderedIds,
    ) {}
}
