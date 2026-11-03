<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Infrastructure\Resend;

use Cronos\Newsletter\Domain\Exceptions\NewsletterError;
use Cronos\Newsletter\Domain\Ports\CampaignMailer;
use Illuminate\Support\Facades\Http;

/** Resend's batch API (POST /emails/batch, up to 100 messages). https://resend.com/docs/api-reference/emails/send-batch-emails */
final class ResendCampaignMailer implements CampaignMailer
{
    public function configured(): bool
    {
        return (string) config('newsletter.resend.api_key') !== '' && (string) config('newsletter.resend.from') !== '';
    }

    public function sendBatch(array $messages, string $idempotencyKey): array
    {
        $this->configured() || throw new NewsletterError('Sending is not set up: add the Resend API key and the sender address.', 'MAILER_NOT_CONFIGURED');
        $replyTo = (string) config('newsletter.resend.reply_to');
        $payload = array_map(static fn (array $m): array => array_filter([
            'from' => (string) config('newsletter.resend.from'), 'to' => [$m['to']], 'subject' => $m['subject'], 'html' => $m['html'], 'text' => $m['text'],
            'reply_to' => $replyTo === '' ? null : $replyTo, 'headers' => $m['headers'] === [] ? null : $m['headers'],
        ], static fn ($v) => $v !== null), $messages);

        $response = Http::withToken((string) config('newsletter.resend.api_key'))->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->timeout((int) config('newsletter.resend.timeout'))->acceptJson()->post(rtrim((string) config('newsletter.resend.base_url'), '/').'/emails/batch', $payload);

        if ($response->successful()) {
            $ids = array_map(static fn ($row) => (string) ($row['id'] ?? ''), (array) $response->json('data', []));
            count($ids) === count($messages) || throw new \RuntimeException('Resend answered with '.count($ids).' ids for '.count($messages).' messages.');

            return $ids;
        }
        // Rate limit and provider trouble: the queue retries the same batch (same idempotency key). A refusal of the content itself will not get better.
        // A rejected API key (401/403) is the operator's problem, not the messages': they stay queued until the key is fixed.
        if (in_array($response->status(), [401, 403, 429], true) || $response->serverError()) {
            throw new \RuntimeException("Resend answered {$response->status()}.");
        }
        throw new NewsletterError('Resend refused the batch: '.mb_substr((string) ($response->json('message') ?? $response->body()), 0, 200), 'MAILER_REJECTED');
    }
}
