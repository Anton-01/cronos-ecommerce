<?php

declare(strict_types=1);

namespace Cronos\Identity\Infrastructure\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class PasswordResetMail extends Mailable
{
    public function __construct(public readonly string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your password');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.password-reset');
    }
}
