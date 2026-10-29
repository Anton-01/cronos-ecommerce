<?php

declare(strict_types=1);

namespace Cronos\Payments\Application;

use Cronos\Payments\Domain\Exceptions\NotFound;
use Cronos\Payments\Domain\Exceptions\PaymentError;
use Cronos\Payments\Domain\Ports\GatewayFactory;
use Cronos\Payments\Domain\Ports\PaymentRepository;
use Cronos\Payments\Domain\Ports\ProviderRepository;
use Cronos\Payments\Domain\Ports\WebhookInbox;
use Cronos\Payments\Domain\ValueObjects\PaymentNotice;
use DateTimeImmutable;
use Throwable;

/**
 * Turns a stored notification into a payment change. Built to be run any number of times for the same event (queue retry,
 * scheduler sweep, or a person pressing "reprocess"): each run either completes the event or leaves it ready for the next try.
 */
final class WebhookProcessor
{
    /** Seconds to wait after the Nth failed attempt. The last entry is the final try; after it the event is "dead" and needs a person. */
    public const BACKOFF = [15, 60, 300, 900, 3600, 14400, 43200];

    public function __construct(
        private readonly WebhookInbox $inbox,
        private readonly GatewayFactory $gateways,
        private readonly ProviderRepository $providers,
        private readonly PaymentRepository $payments,
        private readonly PaymentService $service,
    ) {}

    /** @return 'processed'|'ignored'|'retry'|'dead'|'skipped' */
    public function process(string $eventId): string
    {
        $event = $this->inbox->claim($eventId);
        if ($event === null) {
            return 'skipped'; // being processed elsewhere, or already finished
        }

        try {
            $outcome = $this->handle($event);
        } catch (Throwable $e) {
            return $this->failed($event, $e);
        }

        $this->inbox->update($eventId, ['status' => $outcome['status'], 'processed_at' => now(), 'payment_id' => $outcome['payment_id'], 'last_error' => $outcome['note'], 'next_attempt_at' => null]);

        return $outcome['status'];
    }

    /** @param array<string, mixed> $event @return array{status: 'processed'|'ignored', payment_id: ?string, note: ?string} */
    private function handle(array $event): array
    {
        $mode = $event['mode'] ?? $this->providers->find($event['provider'])['mode'];
        $gateway = $this->gateways->make($event['provider'], $mode);
        $headers = (array) $event['headers'];

        // Providers that cannot be verified from the request alone (PayPal) are verified now, by asking them.
        if (! $event['signature_valid']) {
            $gateway->verifyWebhook($event['raw_body'], $headers) || throw new PaymentError('The notification signature is not valid.', 'INVALID_SIGNATURE');
            $this->inbox->update($event['id'], ['signature_valid' => true, 'mode' => $mode]);
        }

        $notice = $gateway->interpret($event['raw_body'], $headers);
        if ($notice->kind === PaymentNotice::KIND_IGNORE) {
            return ['status' => 'ignored', 'payment_id' => null, 'note' => $notice->failureMessage];
        }

        $payment = ($notice->paymentId !== null ? $this->payments->find($notice->paymentId) : null)
            ?? ($notice->providerReference !== null ? $this->payments->findByReference($event['provider'], $notice->providerReference) : null);

        if ($payment === null) {
            // Often a notification that outran our own bookkeeping (the provider called back before we saved its reference): retry.
            throw new NotFound('The payment of this notification');
        }
        $payment['provider'] === $event['provider'] || throw new PaymentError('The notification names a payment of another provider.', 'PROVIDER_MISMATCH');
        // A sandbox event must never touch a live payment (or the reverse), whatever it claims.
        $payment['mode'] === $mode || throw new PaymentError("The notification came from {$mode} but the payment is {$payment['mode']}.", 'MODE_MISMATCH');

        $this->service->applyNotice($payment['id'], $notice, 'webhook', $event['provider'].':'.$event['event_id']);

        if ($notice->needsCapture) {
            $this->service->finalize($payment['id']);
        }

        return ['status' => 'processed', 'payment_id' => $payment['id'], 'note' => null];
    }

    /** @param array<string, mixed> $event @return 'retry'|'dead'|'ignored' */
    private function failed(array $event, Throwable $e): string
    {
        $attempts = (int) $event['attempts'];
        $permanent = $e instanceof PaymentError && ! $e->retryable && ! $e instanceof NotFound;
        $exhausted = $attempts >= count(self::BACKOFF);

        if ($permanent || $exhausted) {
            // A notification for a payment that simply is not ours, or one that cannot be understood, is not an incident; the rest is.
            $unknown = $e instanceof NotFound && $exhausted;
            $this->inbox->update($event['id'], ['status' => $unknown ? 'ignored' : 'dead', 'last_error' => $this->describe($e), 'next_attempt_at' => null, 'processed_at' => now()]);
            $unknown || report($e);

            return $unknown ? 'ignored' : 'dead';
        }

        $this->inbox->update($event['id'], ['status' => 'failed', 'last_error' => $this->describe($e), 'next_attempt_at' => (new DateTimeImmutable)->modify('+'.self::BACKOFF[max(0, min($attempts - 1, count(self::BACKOFF) - 1))].' seconds')]);

        return 'retry';
    }

    private function describe(Throwable $e): string
    {
        return mb_substr(class_basename($e).': '.$e->getMessage(), 0, 1000);
    }

    /** Puts an event back in the queue from a person's action. */
    public function reset(string $eventId): void
    {
        $event = $this->inbox->find($eventId) ?? throw new NotFound('The notification');
        in_array($event['status'], ['failed', 'dead', 'ignored', 'processed'], true) || throw new PaymentError('That notification is not finished yet.', 'NOT_REPROCESSABLE');

        $this->inbox->update($eventId, ['status' => 'received', 'attempts' => 0, 'next_attempt_at' => now(), 'last_error' => null, 'processed_at' => null]);
    }
}
