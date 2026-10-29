<?php

declare(strict_types=1);

namespace Cronos\Payments\Infrastructure\Jobs;

use Cronos\Payments\Application\WebhookProcessor;
use Cronos\Payments\Domain\Ports\WebhookInbox;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One run of the processor for one stored notification. The processor owns the retry schedule (it records the next attempt in the inbox,
 * so a lost job is picked up by the scheduler sweep); the job only re-queues itself with the right delay.
 */
final class ProcessWebhookJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 120;

    public function __construct(public readonly string $eventId)
    {
        $this->onQueue('payments');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->eventId;
    }

    public function handle(WebhookProcessor $processor): void
    {
        if ($processor->process($this->eventId) === 'retry') {
            $attempt = (int) (app(WebhookInbox::class)->find($this->eventId)['attempts'] ?? 1);
            self::dispatch($this->eventId)->delay(now()->addSeconds(WebhookProcessor::BACKOFF[max(0, min($attempt - 1, count(WebhookProcessor::BACKOFF) - 1))]));
        }
    }
}
