<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\Ports;

interface PasswordResetTokenStore
{
    /** Creates (replacing any previous) a single-use token; returns the plain value. */
    public function create(string $email): string;

    /** Validates and consumes the token in one step. */
    public function consume(string $email, string $token): bool;
}
