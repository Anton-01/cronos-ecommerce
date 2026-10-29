<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\Ports;

interface IdentityNotifier
{
    public function sendEmailVerification(string $email, string $verificationUrl): void;

    public function sendPasswordReset(string $email, string $resetUrl): void;
}
