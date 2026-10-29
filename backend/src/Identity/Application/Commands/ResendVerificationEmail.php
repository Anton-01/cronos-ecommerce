<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Commands;

final readonly class ResendVerificationEmail
{
    public function __construct(
        public string $userId,
    ) {}
}
