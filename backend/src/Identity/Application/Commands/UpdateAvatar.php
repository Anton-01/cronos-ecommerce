<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Commands;

final readonly class UpdateAvatar
{
    public function __construct(
        public string $userId,
        public string $path,
        public string $clientName,
    ) {}
}
