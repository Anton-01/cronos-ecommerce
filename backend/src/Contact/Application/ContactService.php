<?php

declare(strict_types=1);

namespace Cronos\Contact\Application;

use Cronos\Contact\Domain\Events\ContactMessageReceived;
use Cronos\Contact\Domain\Exceptions\ContactError;
use Cronos\Contact\Domain\Ports\ContactMessageRepository;
use Cronos\Contact\Domain\Services\ContactRules;
use Cronos\Shared\Domain\ClientContext;
use Cronos\Shared\Domain\DomainEventDispatcher;
use Cronos\Shared\Domain\HumanVerifier;

/**
 * Receives what visitors write through the contact form. Defences, cheapest first: field validation → honeypot → fill time → Cloudflare Turnstile →
 * rate limits per IP and per e-mail → content signals. Messages that look like spam are KEPT (flagged, no e-mail sent, and the visitor is told "sent"
 * so a bot learns nothing); only a failed human check or a flood is refused outright.
 */
final class ContactService
{
    public function __construct(
        private readonly ContactMessageRepository $messages,
        private readonly HumanVerifier $human,
        private readonly DomainEventDispatcher $events,
    ) {}

    /** @return list<string> */
    public function topics(): array
    {
        return array_values((array) config('contact.topics'));
    }

    /**
     * @param  array<string, mixed>  $input  name, email, phone, topic, order_number, message, plus the two hidden fields: website (honeypot) and rendered_at (ms epoch)
     * @return array{id: string, reference: string, accepted: bool} accepted=false means kept as spam; the visitor is told the same thing either way
     */
    public function submit(array $input, ?string $humanToken, ClientContext $client, ?string $userId, ?string $marketCode, string $locale): array
    {
        $m = ContactRules::normalize($input, $this->topics());
        $signals = [];

        if (trim((string) ($input['website'] ?? '')) !== '') {
            $signals[] = 'honeypot'; // a field no person sees was filled in
        }
        $rendered = (int) ($input['rendered_at'] ?? 0);
        $rendered <= 0
            ? $signals[] = 'no_timer'
            : (($rendered / 1000 > time() + 60 || time() - $rendered / 1000 < (int) config('contact.min_fill_seconds')) && $signals[] = 'too_fast');

        // A bot that tripped a hard signal does not get a Cloudflare round trip.
        $hard = array_intersect($signals, ['honeypot', 'too_fast']) !== [];
        $check = $hard ? 'skipped' : $this->human->verify($humanToken, $client->ip, 'contact');
        $check === HumanVerifier::FAILED && throw new ContactError('We could not confirm you are a person. Reload the page and try again.', 'HUMAN_CHECK_FAILED');
        $check === HumanVerifier::UNAVAILABLE && $signals[] = 'human_check_unavailable';

        if (! $hard) {
            // Floods are refused, not stored: storing them would be the very load the limit exists to avoid.
            $client->ip !== null && $this->messages->countFromIp($client->ip, 60) >= (int) config('contact.max_per_ip_per_hour') && throw new ContactError('Too many messages from your network. Please try again later.', 'RATE_LIMITED');
            $this->messages->countFromEmail($m['email'], 1440) >= (int) config('contact.max_per_email_per_day') && throw new ContactError('You have sent several messages today. We will answer as soon as we can.', 'RATE_LIMITED');
            $client->ip !== null && $this->messages->sameMessageRecently($m['email'], $client->ip, $m['message'], 1440) && $signals[] = 'duplicate';
        }

        $content = ContactRules::spamSignals($m, (int) config('contact.max_links'));
        $all = [...$signals, ...$content];
        // Hard signals, a repeat, or two weak signals together = spam. One weak signal (a customer pasting three product links) is delivered, flagged.
        $spam = array_intersect($all, ['honeypot', 'too_fast', 'duplicate']) !== [] || count(array_diff($all, ['no_timer', 'human_check_unavailable'])) >= 2;

        $id = $this->messages->create($m + [
            'market_code' => $marketCode, 'locale' => $locale, 'status' => $spam ? 'spam' : 'new', 'spam_signals' => $all, 'human_check' => $check,
            'user_id' => $userId, 'ip' => $client->ip, 'country_code' => $client->country, 'timezone' => $client->timezone, 'user_agent' => $client->userAgent === null ? null : mb_substr($client->userAgent, 0, 255),
        ]);

        $spam || $this->events->dispatch(new ContactMessageReceived($id, $marketCode, $locale, $m['name'], $m['email'], $m['phone'], $m['topic'], $m['order_number'], $m['message'], $client->ip, $client->country));

        return ['id' => $id, 'reference' => strtoupper(substr($id, -8)), 'accepted' => ! $spam];
    }

    /** @return array{total: int, rows: list<array<string, mixed>>} */
    public function search(?string $status, ?string $term, int $limit, int $offset): array
    {
        return $this->messages->search($status, $term, max(1, min($limit, 100)), max(0, $offset));
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        return $this->messages->find($id);
    }

    /** @return array<string, mixed> */
    public function setStatus(string $id, string $status, ?string $userId): array
    {
        in_array($status, ['new', 'read', 'replied', 'spam'], true) || throw new ContactError('Unknown status.', 'INVALID_STATUS');
        $this->messages->find($id) ?? throw new ContactError('That message does not exist.', 'MESSAGE_NOT_FOUND');
        $this->messages->setStatus($id, $status, $userId);

        return $this->messages->find($id);
    }
}
