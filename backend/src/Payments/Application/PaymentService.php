<?php

declare(strict_types=1);

namespace Cronos\Payments\Application;

use Brick\Math\BigDecimal;
use Cronos\Payments\Domain\Exceptions\GatewayUnavailable;
use Cronos\Payments\Domain\Exceptions\NotFound;
use Cronos\Payments\Domain\Exceptions\PaymentError;
use Cronos\Payments\Domain\Ports\GatewayFactory;
use Cronos\Payments\Domain\Ports\Outbox;
use Cronos\Payments\Domain\Ports\PaymentRepository;
use Cronos\Payments\Domain\Ports\ProviderRepository;
use Cronos\Payments\Domain\Services\PaymentStateMachine;
use Cronos\Payments\Domain\Services\Scrubber;
use Cronos\Payments\Domain\ValueObjects\PaymentNotice;
use Cronos\Payments\Domain\ValueObjects\PaymentRequest;
use Cronos\Payments\Domain\ValueObjects\PaymentStatus as S;
use Cronos\Shared\Domain\ClientContext;
use Illuminate\Support\Str;

/**
 * The payment lifecycle. Three rules keep money safe:
 *  - every provider call is idempotent (our payment id / refund key), so retries never charge or refund twice;
 *  - every status change happens under a row lock through PaymentStateMachine and is written to the immutable ledger and the outbox
 *    in the same transaction, so no crash can leave a captured payment nobody hears about;
 *  - applying the same provider statement twice changes nothing.
 */
final class PaymentService
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly ProviderRepository $providers,
        private readonly GatewayFactory $gateways,
        private readonly Outbox $outbox,
        private readonly PaymentStateMachine $machine,
    ) {}

    /**
     * Starts collecting money. Calling it again with the same idempotency key returns the same payment (and resumes it if the
     * provider call never completed), so a double click, a retry after a timeout or a replayed request cannot create two charges.
     *
     * @return array{payment: array<string, mixed>, client_action: ?array<string, mixed>, public: array<string, string>}
     */
    public function start(PaymentRequest $request, ?ClientContext $client = null, ?string $userId = null): array
    {
        $config = $this->providers->find($request->provider) ?? throw new NotFound('The payment provider');
        $this->assertAvailable($config, $request);

        $existing = $this->payments->findByKey($request->idempotencyKey);
        if ($existing !== null) {
            ($existing['payable_type'] === $request->payableType && $existing['payable_id'] === $request->payableId && BigDecimal::of($existing['amount'])->isEqualTo($request->amount->amount) && $existing['provider'] === $request->provider)
                || throw new PaymentError('That idempotency key was already used for a different payment.', 'IDEMPOTENCY_MISMATCH');
            $payment = $existing;
        } else {
            $this->gateways->make($request->provider, $config['mode']); // missing credentials fail here, before any row exists
            $id = $this->payments->insert([
                'provider' => $request->provider, 'mode' => $config['mode'], 'payable_type' => $request->payableType, 'payable_id' => $request->payableId,
                'amount' => $request->amount->value(), 'currency' => $request->amount->currency, 'idempotency_key' => $request->idempotencyKey,
                'customer_email' => $request->customerEmail, 'description' => $request->description, 'metadata' => $request->metadata,
                'ip_address' => $client?->ip, 'country_code' => $client?->country, 'timezone' => $client?->timezone,
            ]);
            $payment = $this->payments->find($id);
        }

        // Already past the point of needing the provider (or already created there): hand back what we have.
        if ($payment['provider_reference'] !== null || ! S::isOpen($payment['status'])) {
            return $this->result($payment, $config['mode']);
        }

        $gateway = $this->gateways->make($request->provider, $payment['mode']);
        $started = microtime(true);
        try {
            $created = $gateway->create($payment['id'], $request);
        } catch (GatewayUnavailable $e) {
            $this->ledger($payment['id'], 'create', 'error', null, null, null, [], $e->getMessage(), $started, $userId, $client);
            throw $e; // the payment stays pending: the same key resumes it
        } catch (PaymentError $e) {
            $this->payments->atomically(function () use ($payment, $e, $started, $userId, $client): void {
                $this->ledger($payment['id'], 'create', 'error', null, null, null, [], $e->getMessage(), $started, $userId, $client);
                $this->fail($payment['id'], $e->errorCode(), $e->getMessage());
            });
            throw $e;
        }

        $this->payments->atomically(function () use ($payment, $created, $started, $userId, $client): void {
            $locked = $this->payments->lock($payment['id']);
            $changes = ['provider_reference' => $created->reference, 'client_action' => $created->clientAction];
            $target = $created->status;
            if ($target !== $locked['status'] && ($decision = $this->machine->decide($locked['status'], $target))['outcome'] === 'apply') {
                $changes['status'] = $decision['status'];
            }
            $this->payments->update($payment['id'], $changes);
            $this->ledger($payment['id'], 'create', $changes['status'] ?? $locked['status'], null, $created->reference, null, $created->raw, null, $started, $userId, $client);
        });

        return $this->result($this->payments->find($payment['id']), $payment['mode']);
    }

    /** Asks the provider for the current state and applies it. Safe to call as often as you like (return URLs, reconciliation). */
    public function sync(string $paymentId, ?string $userId = null): array
    {
        $payment = $this->payments->find($paymentId) ?? throw new NotFound('The payment');
        if (! S::isOpen($payment['status']) && $payment['status'] !== S::CAPTURED) {
            return $payment;
        }

        $gateway = $this->gateways->make($payment['provider'], $payment['mode']);
        $started = microtime(true);
        try {
            $notice = $gateway->fetch($payment);
        } catch (PaymentError $e) {
            $this->ledger($paymentId, 'sync', 'error', null, $payment['provider_reference'], null, [], $e->getMessage(), $started, $userId, null);
            throw $e;
        }
        $notice !== null && $this->applyNotice($paymentId, $notice, 'sync', null, $userId);

        return $this->payments->find($paymentId);
    }

    /** The shopper came back from the provider's page: run the second step when the provider has one (PayPal capture), then apply. */
    public function finalize(string $paymentId, ?string $userId = null): array
    {
        $payment = $this->payments->find($paymentId) ?? throw new NotFound('The payment');
        if (! S::isOpen($payment['status']) || $payment['provider_reference'] === null) {
            return $payment;
        }

        $started = microtime(true);
        try {
            $notice = $this->gateways->make($payment['provider'], $payment['mode'])->finalize($payment);
        } catch (PaymentError $e) {
            $this->ledger($paymentId, 'capture', 'error', null, $payment['provider_reference'], null, [], $e->getMessage(), $started, $userId, null);
            throw $e;
        }
        $this->applyNotice($paymentId, $notice, 'finalize', null, $userId);

        return $this->payments->find($paymentId);
    }

    /**
     * Applies one provider statement to a payment, exactly once per (event id): the ledger rejects a replay and the state machine
     * ignores a stale or repeated status.
     *
     * @return array{changed: bool, status: string}
     */
    public function applyNotice(string $paymentId, PaymentNotice $notice, string $source, ?string $externalEventId, ?string $userId = null): array
    {
        return $this->payments->atomically(function () use ($paymentId, $notice, $source, $externalEventId, $userId): array {
            $payment = $this->payments->lock($paymentId) ?? throw new NotFound('The payment');
            $raw = Scrubber::scrub($notice->raw);

            if ($notice->kind === PaymentNotice::KIND_IGNORE) {
                return ['changed' => false, 'status' => $payment['status']];
            }

            return match ($notice->kind) {
                PaymentNotice::KIND_REFUND => $this->applyRefund($payment, $notice, $source, $externalEventId, $raw),
                PaymentNotice::KIND_DISPUTE => $this->applyDispute($payment, $notice, $source, $externalEventId, $raw),
                default => $this->applyStatus($payment, $notice, $source, $externalEventId, $raw, $userId),
            };
        });
    }

    /**
     * @param  array<string, mixed>  $payment
     * @param  array<string, mixed>  $raw
     * @return array{changed: bool, status: string}
     */
    private function applyStatus(array $payment, PaymentNotice $n, string $source, ?string $eventId, array $raw, ?string $userId): array
    {
        $target = $n->status ?? throw new PaymentError('A status notice without a status.', 'INVALID_NOTICE');
        $decision = $this->machine->decide($payment['status'], $target);
        $changes = [];
        $anomaly = $decision['anomaly'];

        if ($target === S::CAPTURED && $decision['outcome'] !== 'reject' && $decision['outcome'] !== 'noop') {
            // Money must match what we asked for: a different amount or currency is never silently accepted as "paid".
            if ($n->amount !== null && (! BigDecimal::of($n->amount)->isEqualTo($payment['amount']) || ($n->currency !== null && strtoupper($n->currency) !== $payment['currency']))) {
                $this->recordEntry($payment['id'], 'webhook', 'anomaly', $n->amount, $n->providerReference, $eventId, $raw, 'amount_mismatch: provider says '.$n->amount.' '.$n->currency.', expected '.$payment['amount'].' '.$payment['currency'], $userId);
                $this->payments->update($payment['id'], ['anomaly' => 'amount_mismatch']);
                $this->outbox->add('payment.needs_review', ['payment_id' => $payment['id'], 'payable_type' => $payment['payable_type'], 'payable_id' => $payment['payable_id'], 'reason' => 'amount_mismatch']);

                return ['changed' => true, 'status' => $payment['status']];
            }
        }

        if ($decision['outcome'] === 'noop' && $source === 'sync') {
            return ['changed' => false, 'status' => $payment['status']]; // a periodic look that finds nothing new is not worth a ledger row
        }
        if ($decision['outcome'] === 'reject' || $decision['outcome'] === 'noop') {
            $this->recordEntry($payment['id'], $source === 'sync' ? 'sync' : 'webhook', $payment['status'], $n->amount, $n->providerReference, $eventId, $raw, $decision['outcome'] === 'reject' ? "stale: provider says {$target}" : null, $userId);

            return ['changed' => false, 'status' => $payment['status']];
        }

        $changes['status'] = $decision['status'];
        $anomaly === null || $changes['anomaly'] = $anomaly;
        if ($n->providerReference !== null && $n->providerReference !== $payment['provider_reference']) {
            $changes['provider_reference'] = $n->providerReference; // PayPal/MP: the capture / payment id replaces the order / preference id
            $changes['metadata'] = $payment['metadata'] + ['initial_reference' => $payment['provider_reference']];
        }
        if ($target === S::CAPTURED) {
            $changes['captured_amount'] = $payment['amount'];
            $changes['failure_code'] = null;
            $changes['failure_message'] = null;
        } elseif ($n->failureCode !== null || $n->failureMessage !== null) {
            $changes['failure_code'] = $n->failureCode;
            $changes['failure_message'] = $n->failureMessage === null ? null : mb_substr($n->failureMessage, 0, 500);
        }

        // A duplicate delivery of the same provider event writes nothing: the ledger's unique key says so.
        if (! $this->recordEntry($payment['id'], $source === 'sync' ? 'sync' : ($source === 'finalize' ? 'capture' : 'webhook'), $changes['status'], $n->amount, $n->providerReference, $eventId, $raw, null, $userId)) {
            return ['changed' => false, 'status' => $payment['status']];
        }
        $this->payments->update($payment['id'], $changes);
        $this->announce($payment, $changes['status'], $anomaly, $n);

        return ['changed' => true, 'status' => $changes['status']];
    }

    /**
     * @param  array<string, mixed>  $payment
     * @param  array<string, mixed>  $raw
     * @return array{changed: bool, status: string}
     */
    private function applyRefund(array $payment, PaymentNotice $n, string $source, ?string $eventId, array $raw): array
    {
        if (! S::isPaid($payment['status']) && $payment['status'] !== S::REFUNDED) {
            $this->recordEntry($payment['id'], 'webhook', $payment['status'], $n->amount, $n->providerReference, $eventId, $raw, 'refund notice for a payment that was never captured', null);

            return ['changed' => false, 'status' => $payment['status']];
        }
        // The refund's own id is the dedupe key when there is one: the shop's call and the provider's webhook then count once.
        $key = $n->refundReference !== null ? 'refund:'.$n->refundReference : $eventId;
        if (! $this->recordEntry($payment['id'], 'refund', 'ok', $n->amount, $n->refundReference ?? $n->providerReference, $key, $raw, null, null)) {
            return ['changed' => false, 'status' => $payment['status']];
        }

        $captured = BigDecimal::of($payment['captured_amount']);
        $total = $n->refundTotal !== null ? BigDecimal::of($n->refundTotal) : BigDecimal::of($payment['refunded_amount'])->plus($n->amount ?? '0');
        $total = $total->isGreaterThan($captured) ? $captured : $total;
        $full = $total->isGreaterThanOrEqualTo($captured);
        $status = $full ? S::REFUNDED : S::PARTIALLY_REFUNDED;

        // A refund the shop started itself is already in `refunds`; one made in the provider's dashboard is recorded here.
        if ($n->refundReference !== null) {
            $this->payments->adoptRefund($payment['id'], $n->refundReference, $n->amount ?? '0', $payment['currency']);
        }
        $this->payments->update($payment['id'], ['status' => $status, 'refunded_amount' => (string) $total->toScale(2)]);
        $this->outbox->add('payment.refunded', ['payment_id' => $payment['id'], 'payable_type' => $payment['payable_type'], 'payable_id' => $payment['payable_id'], 'refunded_total' => (string) $total->toScale(2), 'full' => $full]);

        return ['changed' => true, 'status' => $status];
    }

    /**
     * @param  array<string, mixed>  $payment
     * @param  array<string, mixed>  $raw
     * @return array{changed: bool, status: string}
     */
    private function applyDispute(array $payment, PaymentNotice $n, string $source, ?string $eventId, array $raw): array
    {
        $target = $n->disputeWon ? S::CAPTURED : S::DISPUTED;
        $decision = $this->machine->decide($payment['status'], $target);
        if ($decision['outcome'] !== 'apply' || ! $this->recordEntry($payment['id'], 'dispute', $target, $n->amount, $n->providerReference, $eventId, $raw, null, null)) {
            return ['changed' => false, 'status' => $payment['status']];
        }

        $this->payments->update($payment['id'], ['status' => $target]);
        $target === S::DISPUTED && $this->outbox->add('payment.needs_review', ['payment_id' => $payment['id'], 'payable_type' => $payment['payable_type'], 'payable_id' => $payment['payable_id'], 'reason' => 'dispute']);

        return ['changed' => true, 'status' => $target];
    }

    /**
     * Gives money back, whole or in part. The refund row (with its own idempotency key) is written before the provider is asked, so
     * a crash or timeout in between can be retried with the same key and never refunds twice.
     *
     * @return array<string, mixed> the refund row
     */
    public function refund(string $paymentId, string $amount, ?string $reason, string $idempotencyKey, ?string $userId, ?ClientContext $client = null): array
    {
        (is_numeric($amount) && (float) $amount > 0) || throw new PaymentError('The refund must be greater than zero.', 'INVALID_REFUND');

        $existing = $this->payments->findRefundByKey($idempotencyKey);
        if ($existing !== null) {
            $existing['payment_id'] === $paymentId && BigDecimal::of($existing['amount'])->isEqualTo($amount) || throw new PaymentError('That idempotency key was already used for a different refund.', 'IDEMPOTENCY_MISMATCH');
            if ($existing['status'] !== 'pending') {
                return $existing;
            }
            $refund = $existing; // resume
        } else {
            $refund = $this->payments->atomically(function () use ($paymentId, $amount, $reason, $idempotencyKey, $userId): array {
                $payment = $this->payments->lock($paymentId) ?? throw new NotFound('The payment');
                S::isPaid($payment['status']) && $payment['status'] !== S::DISPUTED || throw new PaymentError('Only a captured payment can be refunded.', 'NOT_REFUNDABLE');
                $remaining = BigDecimal::of($payment['captured_amount'])->minus($this->payments->committedRefunds($paymentId));
                BigDecimal::of($amount)->isLessThanOrEqualTo($remaining) || throw new PaymentError("At most {$remaining} can still be refunded.", 'REFUND_EXCEEDS_PAYMENT');
                $id = $this->payments->insertRefund(['payment_id' => $paymentId, 'amount' => $amount, 'currency' => $payment['currency'], 'reason' => $reason, 'idempotency_key' => $idempotencyKey, 'created_by' => $userId]);

                return $this->payments->findRefundByKey($idempotencyKey) + ['id' => $id];
            });
        }

        $payment = $this->payments->find($paymentId);
        $started = microtime(true);
        try {
            $result = $this->gateways->make($payment['provider'], $payment['mode'])->refund($payment, (string) $refund['amount'], $idempotencyKey, $reason);
        } catch (GatewayUnavailable $e) {
            $this->ledger($paymentId, 'refund', 'error', $refund['amount'], null, null, [], $e->getMessage(), $started, $userId, $client);
            throw $e; // stays pending; retrying with the same key resumes it
        } catch (PaymentError $e) {
            $this->payments->updateRefund($refund['id'], ['status' => 'failed', 'failure_message' => mb_substr($e->getMessage(), 0, 500)]);
            $this->ledger($paymentId, 'refund', 'error', $refund['amount'], null, null, [], $e->getMessage(), $started, $userId, $client);
            throw $e;
        }

        $this->payments->atomically(function () use ($refund, $result, $paymentId, $started, $userId, $client): void {
            $this->payments->updateRefund($refund['id'], ['status' => $result->status, 'provider_reference' => $result->reference, 'failure_message' => $result->failure]);
            $this->ledger($paymentId, 'refund', $result->status === 'failed' ? 'error' : 'ok', $refund['amount'], $result->reference, null, $result->raw, $result->failure, $started, $userId, $client);
            if ($result->status === 'succeeded') {
                $this->applyNotice($paymentId, new PaymentNotice(PaymentNotice::KIND_REFUND, $paymentId, null, amount: (string) $refund['amount'], refundReference: $result->reference, raw: $result->raw), 'refund', 'refund:'.$result->reference);
            }
        });

        return $this->payments->findRefundByKey($idempotencyKey);
    }

    /** Cancels payments still open (e.g. the checkout expired): we stop waiting; if money shows up later it is flagged. */
    public function cancel(string $paymentId, string $why): void
    {
        $this->payments->atomically(function () use ($paymentId, $why): void {
            $payment = $this->payments->lock($paymentId);
            if ($payment === null || ! S::isOpen($payment['status'])) {
                return;
            }
            $this->recordEntry($paymentId, 'void', S::CANCELLED, null, $payment['provider_reference'], null, [], $why, null);
            $this->payments->update($paymentId, ['status' => S::CANCELLED, 'failure_message' => mb_substr($why, 0, 500)]);
            $this->outbox->add('payment.cancelled', ['payment_id' => $paymentId, 'payable_type' => $payment['payable_type'], 'payable_id' => $payment['payable_id']]);
        });
    }

    private function fail(string $paymentId, string $code, string $message): void
    {
        $payment = $this->payments->lock($paymentId);
        if ($payment === null || ! S::isOpen($payment['status'])) {
            return;
        }
        $this->payments->update($paymentId, ['status' => S::FAILED, 'failure_code' => $code, 'failure_message' => mb_substr($message, 0, 500)]);
        $this->outbox->add('payment.failed', ['payment_id' => $paymentId, 'payable_type' => $payment['payable_type'], 'payable_id' => $payment['payable_id'], 'failure_code' => $code, 'failure_message' => mb_substr($message, 0, 500)]);
    }

    /** @param array<string, mixed> $payment */
    private function announce(array $payment, string $status, ?string $anomaly, PaymentNotice $n): void
    {
        $base = ['payment_id' => $payment['id'], 'payable_type' => $payment['payable_type'], 'payable_id' => $payment['payable_id']];
        match ($status) {
            S::CAPTURED => $this->outbox->add('payment.captured', $base + ['amount' => $payment['amount'], 'currency' => $payment['currency'], 'provider' => $payment['provider'], 'mode' => $payment['mode'], 'anomaly' => $anomaly]),
            S::FAILED => $this->outbox->add('payment.failed', $base + ['failure_code' => $n->failureCode, 'failure_message' => $n->failureMessage]),
            S::CANCELLED => $this->outbox->add('payment.cancelled', $base),
            default => null,
        };
        $anomaly === null || $this->outbox->add('payment.needs_review', $base + ['reason' => $anomaly]);
    }

    /** @param array<string, mixed> $config @return void */
    private function assertAvailable(array $config, PaymentRequest $request): void
    {
        $config['is_enabled'] || throw new PaymentError(ProviderCatalog::NAMES[$request->provider].' is not enabled.', 'PROVIDER_DISABLED');
        in_array(strtoupper($request->marketCode), $config['markets'], true) || throw new PaymentError(ProviderCatalog::NAMES[$request->provider]." is not available in {$request->marketCode}.", 'PROVIDER_NOT_AVAILABLE');
        ($config['min_amount'] === null || $request->amount->amount->isGreaterThanOrEqualTo($config['min_amount'])) || throw new PaymentError("The minimum for this payment method is {$config['min_amount']}.", 'AMOUNT_BELOW_MIN');
        ($config['max_amount'] === null || $request->amount->amount->isLessThanOrEqualTo($config['max_amount'])) || throw new PaymentError("The maximum for this payment method is {$config['max_amount']}.", 'AMOUNT_ABOVE_MAX');
    }

    /**
     * @param  array<string, mixed>  $payment
     * @return array{payment: array<string, mixed>, client_action: ?array<string, mixed>, public: array<string, string>}
     */
    private function result(array $payment, string $mode): array
    {
        $public = [];
        try {
            $public = $this->gateways->make($payment['provider'], $mode)->publicConfig();
        } catch (PaymentError) {
            // credentials removed after the payment was created: the shopper can still be told its status
        }

        return ['payment' => $payment, 'client_action' => S::isOpen($payment['status']) ? $payment['client_action'] : null, 'public' => $public];
    }

    /** @param array<string, mixed> $raw */
    private function ledger(string $paymentId, string $type, string $status, ?string $amount, ?string $reference, ?string $eventId, array $raw, ?string $error, float $startedAt, ?string $userId, ?ClientContext $client): void
    {
        $this->payments->addTransaction([
            'payment_id' => $paymentId, 'type' => $type, 'status' => $status, 'amount' => $amount, 'provider_reference' => $reference, 'external_event_id' => $eventId,
            'request_id' => strtolower((string) Str::ulid()), 'raw_response' => Scrubber::scrub($raw), 'error' => $error === null ? null : mb_substr($error, 0, 500),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000), 'created_by' => $userId, 'country_code' => $client?->country, 'timezone' => $client?->timezone,
        ]);
    }

    /** @param array<string, mixed> $raw @return bool false when this exact provider event was already recorded */
    private function recordEntry(string $paymentId, string $type, string $status, ?string $amount, ?string $reference, ?string $eventId, array $raw, ?string $error, ?string $userId): bool
    {
        return $this->payments->addTransaction([
            'payment_id' => $paymentId, 'type' => $type, 'status' => $status, 'amount' => $amount, 'provider_reference' => $reference, 'external_event_id' => $eventId,
            'request_id' => strtolower((string) Str::ulid()), 'raw_response' => $raw, 'error' => $error === null ? null : mb_substr($error, 0, 500), 'created_by' => $userId,
        ]);
    }
}
