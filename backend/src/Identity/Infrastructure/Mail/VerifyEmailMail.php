<?php

declare(strict_types=1);

namespace Cronos\Identity\Infrastructure\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class VerifyEmailMail extends Mailable
{
    public function __construct(public readonly string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify your email address');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.verify-email');
    }
}
