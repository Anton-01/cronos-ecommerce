<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\Ports;

use Cronos\Payments\Domain\ValueObjects\GatewayPayment;
use Cronos\Payments\Domain\ValueObjects\PaymentNotice;
use Cronos\Payments\Domain\ValueObjects\PaymentRequest;
use Cronos\Payments\Domain\ValueObjects\RefundResult;

/**
 * One provider in one mode. Every call that changes something at the provider carries an idempotency key, so a retry after a
 * timeout can never charge or refund twice. Calls throw GatewayUnavailable when nothing is known to have happened (safe to retry)
 * and PaymentError for a definite refusal.
 */
interface PaymentGateway
{
    public function provider(): string;

    /** @param string $paymentId our payment id: sent to the provider as metadata and used as its idempotency key */
    public function create(string $paymentId, PaymentRequest $request): GatewayPayment;

    /** Looks the payment up at the provider (used for return URLs and to recover from lost webhooks). @param array<string, mixed> $payment our payment row */
    public function fetch(array $payment): ?PaymentNotice;

    /** Providers that need a second step after the shopper approves (PayPal capture). Others return the current state. @param array<string, mixed> $payment */
    public function finalize(array $payment): PaymentNotice;

    /** @param array<string, mixed> $payment */
    public function refund(array $payment, string $amount, string $idempotencyKey, ?string $reason): RefundResult;

    /**
     * True when the signature can be checked from the headers and body alone (Stripe, Mercado Pago); false when the provider has to be
     * asked (PayPal), which happens later in the queue.
     */
    public function verifiesLocally(): bool;

    /** @param array<string, string> $headers lower-cased */
    public function verifyWebhook(string $rawBody, array $headers): bool;

    /** @param array<string, string> $headers @return array{id: string, type: ?string} cheap identification, no network */
    public function identify(string $rawBody, array $headers): array;

    /** @param array<string, string> $headers @return PaymentNotice turns a (verified) notification into a statement about a payment; may call the provider */
    public function interpret(string $rawBody, array $headers): PaymentNotice;

    /** Cheap authenticated read-only call: are these credentials good? @throws \Cronos\Payments\Domain\Exceptions\PaymentError */
    public function testConnection(): string;

    /** Public values the shopper's browser needs (publishable key, client id). Never secrets. @return array<string, string> */
    public function publicConfig(): array;
}
