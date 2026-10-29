<?php

declare(strict_types=1);

namespace Cronos\Media\Application\Commands;

final readonly class DetachMedia
{
    public function __construct(
        public string $id,
    ) {}
}
