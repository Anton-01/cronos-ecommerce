<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Application;

use Cronos\Newsletter\Domain\Ports\CampaignRepository;
use Cronos\Newsletter\Domain\Ports\SubscriberRepository;

/**
 * What Resend tells us after sending. Delivered/bounced/complained update the delivery; a hard bounce or a spam complaint also takes the address off the
 * list for good (writing again would hurt the sending domain's reputation). Opens and clicks are deliberately ignored: no tracking of readers.
 */
final class ResendWebhook
{
    public function __construct(private readonly CampaignRepository $campaigns, private readonly SubscriberRepository $subscribers) {}

    /** @param array<string, mixed> $event the decoded body: { type, data: { email_id, to, bounce: { type } } } */
    public function handle(array $event): void
    {
        $type = (string) ($event['type'] ?? '');
        $data = (array) ($event['data'] ?? []);
        $providerId = (string) ($data['email_id'] ?? '');
        if ($providerId === '') {
            return;
        }
        match ($type) {
            'email.delivered' => $this->campaigns->setStatusByProviderId($providerId, 'delivered'),
            'email.bounced' => $this->suppressIf($this->isHard((array) ($data['bounce'] ?? [])), $this->campaigns->setStatusByProviderId($providerId, 'bounced'), 'bounce'),
            'email.complained' => $this->suppressIf(true, $this->campaigns->setStatusByProviderId($providerId, 'complained'), 'complaint'),
            default => null,
        };
    }

    /** A soft bounce (full mailbox, temporary) is not a reason to drop anybody; Resend marks permanent ones. @param array<string, mixed> $bounce */
    private function isHard(array $bounce): bool
    {
        $type = strtolower((string) ($bounce['type'] ?? 'permanent'));

        return ! in_array($type, ['transient', 'temporary', 'soft'], true);
    }

    /** @param array<string, mixed>|null $delivery */
    private function suppressIf(bool $when, ?array $delivery, string $by): void
    {
        if (! $when || $delivery === null) {
            return;
        }
        $row = $this->subscribers->find((string) $delivery['subscriber_id']);
        if ($row !== null && $row['status'] !== 'unsubscribed') {
            $this->subscribers->update($row['id'], ['status' => 'unsubscribed', 'unsubscribed_at' => now(), 'unsubscribed_by' => $by]);
        }
    }
}
