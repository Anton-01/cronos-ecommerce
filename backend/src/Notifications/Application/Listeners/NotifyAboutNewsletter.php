<?php

declare(strict_types=1);

namespace Cronos\Notifications\Application\Listeners;

use Cronos\Markets\Domain\Ports\MarketProfileRepository;
use Cronos\Newsletter\Domain\Events\NewsletterConfirmationRequested;
use Cronos\Newsletter\Domain\Events\NewsletterConfirmed;
use Cronos\Notifications\Application\NotificationService;

/** The two e-mails of the double opt-in: "confirm your address" and, once confirmed, a welcome that carries the unsubscribe link. */
final class NotifyAboutNewsletter
{
    public function __construct(private readonly NotificationService $notifications, private readonly MarketProfileRepository $profiles) {}

    public function confirmationRequested(NewsletterConfirmationRequested $e): void
    {
        $this->notifications->send('newsletter_confirm', "newsletter-confirm:{$e->subscriberId}:{$e->attempt}", $e->email, null, $e->marketCode, [
            'reply_to' => $this->replyTo($e->marketCode), 'shop' => $this->shop($e->marketCode), 'url' => $this->url($e->locale, 'newsletter/confirm', $e->token), 'days' => 7,
        ], 'newsletter', $e->subscriberId, $e->locale);
    }

    public function confirmed(NewsletterConfirmed $e): void
    {
        $this->notifications->send('newsletter_welcome', "newsletter-welcome:{$e->subscriberId}:".substr(sha1($e->unsubscribeToken.now()->format('Y-m-d')), 0, 8), $e->email, null, $e->marketCode, [
            'reply_to' => $this->replyTo($e->marketCode), 'shop' => $this->shop($e->marketCode), 'unsubscribe_url' => $this->url($e->locale, 'newsletter/unsubscribe', $e->unsubscribeToken),
        ], 'newsletter', $e->subscriberId, $e->locale);
    }

    private function url(string $locale, string $path, string $token): string
    {
        return rtrim((string) config('app.storefront_url'), '/').'/'.config("seo.locales.{$locale}.prefix", 'es-mx').'/'.$path.'?token='.rawurlencode($token);
    }

    private function replyTo(?string $market): ?string
    {
        return $market === null ? null : ($this->profiles->profile($market)['contact_email'] ?? null);
    }

    private function shop(?string $market): string
    {
        return NotifyAboutContact::plain((string) (($market === null ? null : ($this->profiles->profile($market)['trade_name'] ?? null)) ?? config('app.name')));
    }
}
