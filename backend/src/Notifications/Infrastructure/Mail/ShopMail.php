<?php

declare(strict_types=1);

namespace Cronos\Notifications\Infrastructure\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** One mailable for every shop template: the subject and body come from lang/{locale}/shop_mail.php and resources/views/mail/shop. */
final class ShopMail extends Mailable
{
    /** @param array<string, mixed> $data */
    public function __construct(public readonly string $template, public readonly string $mailLocale, public readonly array $data)
    {
        $this->locale($mailLocale);
    }

    public function envelope(): Envelope
    {
        $scalars = array_filter($this->data, 'is_scalar');
        $this->template === 'order_status' && $scalars['status_label'] = __("shop_mail.order_status.labels.{$this->data['status']}", [], $this->mailLocale);

        return new Envelope(subject: __("shop_mail.{$this->template}.subject", $scalars, $this->mailLocale));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.shop.'.str_replace('_', '-', $this->template), with: ['data' => $this->data, 'l' => $this->mailLocale]);
    }
}
