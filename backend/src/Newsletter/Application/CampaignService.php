<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Application;

use Cronos\Content\Domain\Ports\MarkdownRenderer;
use Cronos\Markets\Domain\Ports\MarketProfileRepository;
use Cronos\Newsletter\Domain\Events\CampaignReady;
use Cronos\Newsletter\Domain\Exceptions\NewsletterError;
use Cronos\Newsletter\Domain\Ports\CampaignMailer;
use Cronos\Newsletter\Domain\Ports\CampaignRepository;
use Cronos\Newsletter\Domain\Ports\EmailRenderer;
use Cronos\Newsletter\Domain\Ports\SubscriberRepository;
use Cronos\Newsletter\Domain\Services\CampaignRules;
use Cronos\Newsletter\Domain\Services\NewsletterRules;
use Cronos\Shared\Domain\DomainEventDispatcher;

/**
 * Newsletter campaigns. Written as drafts in the back office, then sent now or at a time. Sending freezes the recipients (confirmed subscribers of
 * the audience, once each), splits them in batches and hands them to Resend one batch at a time; every step can be repeated without mailing anybody twice.
 */
final class CampaignService
{
    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly SubscriberRepository $subscribers,
        private readonly CampaignMailer $mailer,
        private readonly EmailRenderer $renderer,
        private readonly MarkdownRenderer $markdown,
        private readonly NewsletterTokens $tokens,
        private readonly MarketProfileRepository $profiles,
        private readonly DomainEventDispatcher $events,
    ) {}

    /** @return list<string> */
    public function locales(): array
    {
        return array_values((array) config('app.supported_locales'));
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $status): array
    {
        return $this->campaigns->all($status);
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        return $this->campaigns->find($id);
    }

    /**
     * @param  array<string, mixed>  $input  name, audience, translations
     */
    public function create(array $input, ?string $userId): string
    {
        $name = trim((string) ($input['name'] ?? ''));
        $name === '' && throw new NewsletterError('Give the campaign a name.', 'INVALID_CAMPAIGN', ['name' => 'Escribe un nombre.']);

        return $this->campaigns->create([
            'name' => mb_substr($name, 0, 160), 'status' => 'draft', 'audience' => json_encode(CampaignRules::audience((array) ($input['audience'] ?? []))),
            'translations' => json_encode(CampaignRules::translations((array) ($input['translations'] ?? []), $this->locales()), JSON_FORCE_OBJECT), 'created_by' => $userId, 'updated_by' => $userId,
        ]);
    }

    /** @param array<string, mixed> $input only the keys present change */
    public function update(string $id, array $input, ?string $userId): void
    {
        $c = $this->editable($id);
        $changes = ['updated_by' => $userId];
        if (array_key_exists('name', $input)) {
            trim((string) $input['name']) === '' && throw new NewsletterError('Give the campaign a name.', 'INVALID_CAMPAIGN', ['name' => 'Escribe un nombre.']);
            $changes['name'] = mb_substr(trim((string) $input['name']), 0, 160);
        }
        array_key_exists('audience', $input) && $changes['audience'] = json_encode(CampaignRules::audience((array) $input['audience']));
        array_key_exists('translations', $input) && $changes['translations'] = json_encode(CampaignRules::translations((array) $input['translations'], $this->locales()), JSON_FORCE_OBJECT);
        $this->campaigns->update($c['id'], $changes);
    }

    public function delete(string $id): void
    {
        $c = $this->campaigns->find($id) ?? throw new NewsletterError('That campaign does not exist.', 'CAMPAIGN_NOT_FOUND');
        $c['status'] === 'draft' || throw new NewsletterError('Only drafts can be deleted; a campaign that went out stays as a record.', 'CAMPAIGN_LOCKED');
        $this->campaigns->delete($id);
    }

    /** @return array{html: string, subject: string, locale: string} the message as the given subscriber would receive it (a made-up one for the preview) */
    public function preview(string $id, ?string $locale): array
    {
        $c = $this->campaigns->find($id) ?? throw new NewsletterError('That campaign does not exist.', 'CAMPAIGN_NOT_FOUND');
        $l = CampaignRules::pick($c['translations'], $locale ?? $this->locales()[0], $this->locales()[0]) ?? throw new NewsletterError('Write the message first.', 'INVALID_CAMPAIGN');
        $built = $this->build($c, $l, null, 'preview-token');

        return ['html' => $built['html'], 'subject' => $built['subject'], 'locale' => $l];
    }

    /** Sends the campaign to one address (the editor's own), marked as a test. Never touches the list or the delivery rows. */
    public function sendTest(string $id, string $email, ?string $locale): void
    {
        $this->mailer->configured() || throw new NewsletterError('Sending is not set up: add the Resend API key and the sender address.', 'MAILER_NOT_CONFIGURED');
        $email = NewsletterRules::email($email);
        $c = $this->campaigns->find($id) ?? throw new NewsletterError('That campaign does not exist.', 'CAMPAIGN_NOT_FOUND');
        $l = CampaignRules::pick($c['translations'], $locale ?? $this->locales()[0], $this->locales()[0]) ?? throw new NewsletterError('Write the message first.', 'INVALID_CAMPAIGN');
        $m = $this->build($c, $l, null, 'test-token');
        $this->mailer->sendBatch([['to' => $email, 'subject' => '[TEST] '.$m['subject'], 'html' => $m['html'], 'text' => $m['text'], 'headers' => []]], "campaign-test:{$id}:".sha1($email.microtime()));
    }

    /** Sends now (`$at` null) or at a time. @param  ?\DateTimeInterface  $at */
    public function send(string $id, ?\DateTimeInterface $at, ?string $userId): void
    {
        $c = $this->campaigns->find($id) ?? throw new NewsletterError('That campaign does not exist.', 'CAMPAIGN_NOT_FOUND');
        CampaignRules::assertSendable($c);
        $this->mailer->configured() || throw new NewsletterError('Sending is not set up: add the Resend API key and the sender address.', 'MAILER_NOT_CONFIGURED');
        if ($at !== null && $at->getTimestamp() > now()->getTimestamp() + 30) {
            $this->campaigns->update($id, ['status' => 'scheduled', 'scheduled_at' => $at, 'updated_by' => $userId]);

            return;
        }
        $this->launch($id);
    }

    public function cancel(string $id, ?string $userId): void
    {
        $c = $this->campaigns->find($id) ?? throw new NewsletterError('That campaign does not exist.', 'CAMPAIGN_NOT_FOUND');
        in_array($c['status'], ['scheduled', 'sending'], true) || throw new NewsletterError('Only a scheduled or sending campaign can be cancelled.', 'CAMPAIGN_LOCKED');
        // Whatever Resend already accepted cannot be recalled; what is still queued is never sent.
        $this->campaigns->update($id, ['status' => 'cancelled', 'finished_at' => now(), 'updated_by' => $userId]);
    }

    /** Called every minute: launches the scheduled campaigns whose time has come. @return int how many were launched */
    public function launchDue(): int
    {
        $n = 0;
        foreach ($this->campaigns->dueIds() as $id) {
            $this->launch($id);
            $n++;
        }

        return $n;
    }

    /** Freezes the recipients and announces the batches. */
    private function launch(string $id): void
    {
        $c = $this->campaigns->find($id);
        $total = $this->campaigns->snapshotRecipients($id, $c['audience'], (int) config('newsletter.batch_size'));
        $this->campaigns->update($id, ['status' => $total === 0 ? 'sent' : 'sending', 'started_at' => now(), 'total' => $total, 'finished_at' => $total === 0 ? now() : null, 'scheduled_at' => null]);
        $total === 0 || $this->events->dispatch(new CampaignReady($id));
    }

    /** @return list<int> the batches still to send, for the queue to schedule */
    public function pendingBatches(string $id): array
    {
        return $this->campaigns->pendingBatches($id);
    }

    /**
     * Sends one batch. Safe to run again after a crash or a timeout: only deliveries still `queued` are taken, and Resend answers a repeated batch with
     * the same result (idempotency key) instead of mailing it twice. A failure that is the provider's (429, 5xx) is thrown so the queue retries it.
     */
    public function sendBatch(string $id, int $batch): void
    {
        $c = $this->campaigns->find($id);
        if ($c === null || $c['status'] !== 'sending') {
            return; // cancelled meanwhile: what is queued stays unsent
        }
        $rows = $this->campaigns->queuedInBatch($id, $batch);
        if ($rows !== []) {
            $messages = [];
            foreach ($rows as $r) {
                $l = CampaignRules::pick($c['translations'], $r['locale'], $this->locales()[0]) ?? $this->locales()[0];
                $m = $this->build($c, $l, $r['market_code'], $this->tokens->unsubscribe($r['subscriber_id']));
                $messages[] = ['to' => $r['email'], 'subject' => $m['subject'], 'html' => $m['html'], 'text' => $m['text'], 'headers' => $m['headers']];
            }
            $ids = array_map(static fn (array $r) => $r['id'], $rows);
            try {
                $providerIds = $this->mailer->sendBatch($messages, "campaign:{$id}:batch:{$batch}");
            } catch (NewsletterError $e) {
                // The provider refused these addresses/messages for good (4xx other than 429): they are failed, the campaign goes on.
                $e->errorCode() === 'MAILER_REJECTED' || throw $e;
                $this->campaigns->markFailed($ids, mb_substr($e->getMessage(), 0, 300));
                $this->finishIfDone($id);

                return;
            }
            $this->campaigns->markSent($ids, $providerIds);
        }
        $this->finishIfDone($id);
    }

    public function finishIfDone(string $id): void
    {
        if ($this->campaigns->pendingBatches($id) === []) {
            $this->campaigns->update($id, ['status' => 'sent', 'finished_at' => now()]);
        }
    }

    /** @return array<string, int> */
    public function counts(string $id): array
    {
        return $this->campaigns->counts($id);
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array{subject: string, html: string, text: string, headers: array<string, string>}
     */
    private function build(array $c, string $locale, ?string $market, string $unsubscribeToken): array
    {
        $t = $c['translations'][$locale];
        $profile = $market === null ? null : $this->profiles->profile($market);
        $shop = (string) ($profile['trade_name'] ?? $profile['legal_name'] ?? config('app.name'));
        $fiscal = (array) ($profile['fiscal_address'] ?? []);
        $address = trim(implode(', ', array_filter([$fiscal['street'] ?? null, $fiscal['city'] ?? null, $fiscal['state'] ?? null, $fiscal['zip'] ?? null, $fiscal['countryCode'] ?? $fiscal['country_code'] ?? null]))) ?: (string) config('newsletter.postal_fallback');
        $page = rtrim((string) config('app.storefront_url'), '/').'/'.config("seo.locales.{$locale}.prefix", 'es-mx').'/newsletter/unsubscribe?token='.rawurlencode($unsubscribeToken);
        $oneClick = rtrim((string) config('app.url'), '/').'/newsletter/unsubscribe?token='.rawurlencode($unsubscribeToken);
        $es = str_starts_with($locale, 'es');
        $out = $this->renderer->render([
            'subject' => $t['subject'], 'preheader' => $t['preheader'], 'body_html' => $this->markdown->render($t['body_md']), 'shop' => $shop, 'address' => $address, 'unsubscribe_url' => $page,
            'reason' => $es ? "Recibes este correo porque te suscribiste al boletín de {$shop}." : "You receive this e-mail because you subscribed to the {$shop} newsletter.",
            'unsubscribe_label' => $es ? 'Darme de baja' : 'Unsubscribe',
        ]);

        return ['subject' => $t['subject'], 'html' => $out['html'], 'text' => $out['text'], 'headers' => [
            // RFC 8058 one-click: mail apps show their own "Unsubscribe" button and POST to this URL, no page, no login.
            'List-Unsubscribe' => "<{$oneClick}>", 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]];
    }

    /** @return array<string, mixed> */
    private function editable(string $id): array
    {
        $c = $this->campaigns->find($id) ?? throw new NewsletterError('That campaign does not exist.', 'CAMPAIGN_NOT_FOUND');
        in_array($c['status'], ['draft', 'scheduled'], true) || throw new NewsletterError('A campaign that is already going out cannot be edited.', 'CAMPAIGN_LOCKED');

        return $c;
    }
}
