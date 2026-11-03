<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Application;

use Cronos\Newsletter\Domain\Events\NewsletterConfirmationRequested;
use Cronos\Newsletter\Domain\Events\NewsletterConfirmed;
use Cronos\Newsletter\Domain\Exceptions\NewsletterError;
use Cronos\Newsletter\Domain\Ports\SubscriberRepository;
use Cronos\Newsletter\Domain\Services\NewsletterRules;
use Cronos\Shared\Domain\ClientContext;
use Cronos\Shared\Domain\DomainEventDispatcher;
use Cronos\Shared\Domain\HumanVerifier;
use Illuminate\Support\Facades\Cache;

/**
 * Double opt-in newsletter list. Asking creates a PENDING row and sends a link; only following the link makes it CONFIRMED, and only confirmed
 * addresses may receive anything. Every sign-up answers the same ("check your e-mail") whether the address is new, pending or already confirmed,
 * so the form cannot be used to find out who is on the list.
 */
final class NewsletterService
{
    /** Minutes before a second request for the same pending address sends the link again (a person who did not get it can ask once more). */
    private const RESEND_AFTER = 10;

    public function __construct(
        private readonly SubscriberRepository $subscribers,
        private readonly NewsletterTokens $tokens,
        private readonly HumanVerifier $human,
        private readonly DomainEventDispatcher $events,
    ) {}

    /**
     * @param  array{email: string, source?: string, website?: ?string, rendered_at?: ?string}  $in
     * @param  ?string  $userId  a signed-in person has already been through the registration checks: no Turnstile for them
     */
    public function subscribe(array $in, ?string $humanToken, ClientContext $client, ?string $userId, ?string $marketCode, string $locale): void
    {
        $email = NewsletterRules::email((string) $in['email']);

        // Hidden field filled in, or sent faster than a person can type: pretend it worked, do nothing.
        if (trim((string) ($in['website'] ?? '')) !== '') {
            return;
        }
        $rendered = (int) ($in['rendered_at'] ?? 0);
        if ($rendered > 0 && time() - $rendered / 1000 < (int) config('newsletter.min_fill_seconds')) {
            return;
        }

        if ($userId === null) {
            $this->human->verify($humanToken, $client->ip, 'newsletter') === HumanVerifier::FAILED && throw new NewsletterError('We could not confirm you are a person. Reload the page and try again.', 'HUMAN_CHECK_FAILED');
        }
        $this->hit("newsletter:ip:{$client->ip}", (int) config('newsletter.max_per_ip_per_hour'), 3600, $client->ip !== null);
        $this->hit('newsletter:email:'.sha1($email), (int) config('newsletter.max_per_email_per_day'), 86400);

        $row = $this->subscribers->findByEmail($email);
        $meta = ['consented_at' => now(), 'consent_ip' => $client->ip, 'consent_country' => $client->country, 'consent_user_agent' => $client->userAgent === null ? null : mb_substr($client->userAgent, 0, 255)];

        if ($row === null) {
            $id = $this->subscribers->create(['email' => $email, 'status' => 'pending', 'locale' => $locale, 'market_code' => $marketCode, 'source' => NewsletterRules::source((string) ($in['source'] ?? 'footer')), 'user_id' => $userId] + $meta);
            $this->askToConfirm($id, $email, $locale, $marketCode, 0);

            return;
        }
        if ($row['status'] === 'confirmed') {
            return; // already on the list: nothing to send, nothing to reveal
        }
        // Pending: one resend per window. Unsubscribed: they are asking to come back, which is a new consent.
        $recent = $row['confirmation_sent_at'] !== null && strtotime((string) $row['confirmation_sent_at']) > now()->getTimestamp() - self::RESEND_AFTER * 60;
        if ($row['status'] === 'pending' && $recent) {
            return;
        }
        $this->subscribers->update($row['id'], ['status' => 'pending', 'locale' => $locale, 'unsubscribed_at' => null, 'unsubscribed_by' => null, 'confirmed_at' => null] + $meta);
        $this->askToConfirm($row['id'], $email, $locale, $marketCode ?? $row['market_code'], (int) $row['confirmation_count']);
    }

    /** @return array{email: string} */
    public function confirm(string $token, ClientContext $client): array
    {
        $row = $this->rowFor($token, 'confirm', checkExpiry: true);
        if ($row['status'] !== 'confirmed') {
            $this->subscribers->update($row['id'], ['status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_ip' => $client->ip, 'unsubscribed_at' => null, 'unsubscribed_by' => null]);
            $this->events->dispatch(new NewsletterConfirmed($row['id'], $row['email'], $row['locale'], $row['market_code'], $this->tokens->unsubscribe($row['id'])));
        }

        return ['email' => $row['email']];
    }

    /** @return array{email: string} */
    public function unsubscribe(string $token): array
    {
        $row = $this->rowFor($token, 'unsub', checkExpiry: false);
        $row['status'] === 'unsubscribed' || $this->subscribers->update($row['id'], ['status' => 'unsubscribed', 'unsubscribed_at' => now(), 'unsubscribed_by' => 'subscriber']);

        return ['email' => $row['email']];
    }

    /** @return array{total: int, counts: array<string, int>, rows: list<array<string, mixed>>} */
    public function search(?string $status, ?string $term, int $limit, int $offset): array
    {
        return $this->subscribers->search($status, $term, max(1, min($limit, 200)), max(0, $offset));
    }

    public function removeByAdmin(string $id): void
    {
        $this->subscribers->find($id) ?? throw new NewsletterError('That subscriber does not exist.', 'SUBSCRIBER_NOT_FOUND');
        $this->subscribers->update($id, ['status' => 'unsubscribed', 'unsubscribed_at' => now(), 'unsubscribed_by' => 'admin']);
    }

    /** Right to erasure: the row (and with it the proof of consent) is deleted for good. */
    public function erase(string $id): void
    {
        $this->subscribers->find($id) ?? throw new NewsletterError('That subscriber does not exist.', 'SUBSCRIBER_NOT_FOUND');
        $this->subscribers->delete($id);
    }

    /** @return array<string, mixed> */
    private function rowFor(string $token, string $purpose, bool $checkExpiry): array
    {
        $t = $this->tokens->read($token);
        ($t === null || $t['purpose'] !== $purpose) && throw new NewsletterError('This link is not valid.', 'INVALID_TOKEN');
        $checkExpiry && $t['expired'] && throw new NewsletterError('This link has expired. Subscribe again to get a new one.', 'EXPIRED_TOKEN');

        return $this->subscribers->find($t['id']) ?? throw new NewsletterError('This link is not valid.', 'INVALID_TOKEN');
    }

    private function askToConfirm(string $id, string $email, string $locale, ?string $marketCode, int $before): void
    {
        $this->subscribers->update($id, ['confirmation_sent_at' => now(), 'confirmation_count' => $before + 1]);
        $this->events->dispatch(new NewsletterConfirmationRequested($id, $email, $locale, $marketCode, $this->tokens->confirm($id), $before + 1));
    }

    private function hit(string $key, int $max, int $seconds, bool $active = true): void
    {
        if (! $active) {
            return;
        }
        Cache::add($key, 0, $seconds);
        Cache::increment($key) > $max && throw new NewsletterError('Too many attempts. Please try again later.', 'RATE_LIMITED');
    }
}
