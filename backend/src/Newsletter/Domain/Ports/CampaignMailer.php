<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Domain\Ports;

interface CampaignMailer
{
    public function configured(): bool;

    /**
     * Sends up to 100 messages in one call. The idempotency key makes a retry of the same batch safe: the provider answers with the first result
     * instead of mailing everybody again.
     *
     * @param  list<array{to: string, subject: string, html: string, text: string, headers: array<string, string>}>  $messages
     * @return list<string> the provider's id of each message, in order
     */
    public function sendBatch(array $messages, string $idempotencyKey): array;
}
