<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\Ports;

interface EmailVerificationLinks
{
    public function make(string $userId, string $email): string;

    public function isValid(string $userId, string $email, int $expires, string $signature): bool;
}
