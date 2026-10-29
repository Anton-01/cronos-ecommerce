<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Commands;

final readonly class VerifyEmail
{
    public function __construct(
        public string $userId,
        public int $expires,
        public string $signature,
    ) {}
}
