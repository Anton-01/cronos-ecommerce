<?php

declare(strict_types=1);

namespace Cronos\Payments\Application;

use Cronos\Payments\Domain\Events\PaymentCancelled;
use Cronos\Payments\Domain\Events\PaymentCaptured;
use Cronos\Payments\Domain\Events\PaymentFailed;
use Cronos\Payments\Domain\Events\PaymentNeedsReview;
use Cronos\Payments\Domain\Events\PaymentRefunded;
use Cronos\Payments\Domain\Ports\Outbox;
use Cronos\Shared\Domain\DomainEventDispatcher;
use DateTimeImmutable;
use Throwable;

/**
 * Delivers the messages written next to each payment change. At-least-once: a listener that throws is retried with backoff and may
 * see the same message again, so every listener must be idempotent (they are: they key their work on the payment id).
 */
final class OutboxRelay
{
    public const MAX_ATTEMPTS = 12;

    public function __construct(private readonly Outbox $outbox, private readonly DomainEventDispatcher $events) {}

    /** @return int messages delivered */
    public function run(int $limit = 100): int
    {
        $delivered = 0;
        foreach ($this->outbox->claimDue($limit) as $message) {
            try {
                $this->events->dispatch($this->event($message['type'], $message['payload']));
                $this->outbox->markDispatched($message['id']);
                $delivered++;
            } catch (Throwable $e) {
                $attempts = $message['attempts'] + 1;
                $giveUp = $attempts >= self::MAX_ATTEMPTS;
                $this->outbox->markFailed($message['id'], mb_substr(class_basename($e).': '.$e->getMessage(), 0, 1000), (new DateTimeImmutable)->modify('+'.min(3600, 5 * (2 ** $attempts)).' seconds'), $giveUp);
                $giveUp && report($e);
            }
        }

        return $delivered;
    }

    /** @param array<string, mixed> $p */
    private function event(string $type, array $p): object
    {
        return match ($type) {
            'payment.captured' => new PaymentCaptured($p['payment_id'], $p['payable_type'], $p['payable_id'], $p['amount'], $p['currency'], $p['provider'], $p['mode'], $p['anomaly'] ?? null),
            'payment.failed' => new PaymentFailed($p['payment_id'], $p['payable_type'], $p['payable_id'], $p['failure_code'] ?? null, $p['failure_message'] ?? null),
            'payment.cancelled' => new PaymentCancelled($p['payment_id'], $p['payable_type'], $p['payable_id']),
            'payment.refunded' => new PaymentRefunded($p['payment_id'], $p['payable_type'], $p['payable_id'], $p['refunded_total'], (bool) $p['full']),
            'payment.needs_review' => new PaymentNeedsReview($p['payment_id'], $p['payable_type'], $p['payable_id'], $p['reason']),
            default => throw new \RuntimeException("Unknown outbox message type [{$type}]."),
        };
    }
}
