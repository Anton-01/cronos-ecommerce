<?php

declare(strict_types=1);

namespace Cronos\Identity\Infrastructure\Adapters;

use Cronos\Identity\Domain\Ports\IdentityNotifier;
use Cronos\Identity\Infrastructure\Mail\PasswordResetMail;
use Cronos\Identity\Infrastructure\Mail\VerifyEmailMail;
use Illuminate\Support\Facades\Mail;

final class MailIdentityNotifier implements IdentityNotifier
{
    public function sendEmailVerification(string $email, string $verificationUrl): void
    {
        Mail::to($email)->send(new VerifyEmailMail($verificationUrl));
    }

    public function sendPasswordReset(string $email, string $resetUrl): void
    {
        Mail::to($email)->send(new PasswordResetMail($resetUrl));
    }
}
