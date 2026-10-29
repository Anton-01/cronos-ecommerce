<?php

declare(strict_types=1);

namespace Cronos\Payments\Infrastructure\Gateways;

use Cronos\Payments\Domain\Exceptions\InvalidWebhook;
use Cronos\Payments\Domain\Exceptions\PaymentError;
use Cronos\Payments\Domain\Ports\PaymentGateway;
use Cronos\Payments\Domain\Services\MinorUnits;
use Cronos\Payments\Domain\ValueObjects\GatewayPayment;
use Cronos\Payments\Domain\ValueObjects\PaymentNotice;
use Cronos\Payments\Domain\ValueObjects\PaymentRequest;
use Cronos\Payments\Domain\ValueObjects\PaymentStatus as S;
use Cronos\Payments\Domain\ValueObjects\Provider;
use Cronos\Payments\Domain\ValueObjects\RefundResult;
use Cronos\Pricing\Domain\ValueObjects\Money;

/** Stripe PaymentIntents. The shopper's browser confirms the intent with Stripe.js using the client secret; we learn the result by webhook. */
final class StripeGateway implements PaymentGateway
{
    private const BASE = 'https://api.stripe.com';

    private const SIGNATURE_TOLERANCE = 300;

    /** @param array<string, string> $credentials */
    public function __construct(private readonly GatewayHttp $http, private readonly array $credentials, private readonly string $mode) {}

    public function provider(): string
    {
        return Provider::STRIPE;
    }

    public function create(string $paymentId, PaymentRequest $request): GatewayPayment
    {
        $form = [
            'amount' => MinorUnits::of($request->amount),
            'currency' => strtolower($request->amount->currency),
            'automatic_payment_methods' => ['enabled' => 'true'],
            'metadata' => ['payment_id' => $paymentId, 'payable_type' => $request->payableType, 'payable_id' => $request->payableId, 'market' => $request->marketCode],
        ];
        $request->description === null || $form['description'] = mb_substr($request->description, 0, 300);
        $request->customerEmail === null || $form['receipt_email'] = $request->customerEmail;

        $response = $this->http->send(Provider::STRIPE, $this->mode, 'POST', self::BASE.'/v1/payment_intents', $this->headers(['Idempotency-Key' => 'pi-'.$paymentId]), form: $form);
        $response->successful() || $this->http->refuse($response, 'Stripe');

        return new GatewayPayment(
            (string) $response->json('id'), $this->status((string) $response->json('status')),
            ['type' => 'client_secret', 'client_secret' => $response->json('client_secret'), 'publishable_key' => $this->credentials['publishable_key'] ?? null],
            (array) $response->json(),
        );
    }

    public function fetch(array $payment): ?PaymentNotice
    {
        $reference = $payment['provider_reference'] ?? null;
        if ($reference === null) {
            return null;
        }
        $response = $this->http->send(Provider::STRIPE, $this->mode, 'GET', self::BASE.'/v1/payment_intents/'.$reference, $this->headers(), query: ['expand' => ['latest_charge']]);
        $response->successful() || $this->http->refuse($response, 'Stripe');

        return $this->intentNotice((array) $response->json());
    }

    public function finalize(array $payment): PaymentNotice
    {
        return $this->fetch($payment) ?? PaymentNotice::ignore('no reference yet');
    }

    public function refund(array $payment, string $amount, string $idempotencyKey, ?string $reason): RefundResult
    {
        $form = ['payment_intent' => $payment['provider_reference'], 'amount' => MinorUnits::of(new Money($amount, $payment['currency'])), 'metadata' => ['payment_id' => $payment['id'], 'reason' => (string) $reason]];
        $response = $this->http->send(Provider::STRIPE, $this->mode, 'POST', self::BASE.'/v1/refunds', $this->headers(['Idempotency-Key' => 'refund-'.$idempotencyKey]), form: $form);
        $response->successful() || $this->http->refuse($response, 'Stripe', 'REFUND_REFUSED');

        $status = (string) $response->json('status');

        return new RefundResult((string) $response->json('id'), $status === 'succeeded' ? 'succeeded' : (in_array($status, ['failed', 'canceled'], true) ? 'failed' : 'pending'), (array) $response->json(), $response->json('failure_reason'));
    }

    public function verifiesLocally(): bool
    {
        return true;
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $secret = $this->credentials['webhook_secret'] ?? '';
        $header = $headers['stripe-signature'] ?? '';
        if ($secret === '' || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            $k === 't' && $timestamp = $v;
            $k === 'v1' && $signatures[] = $v;
        }
        if ($timestamp === null || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false; // a captured, correct signature cannot be replayed hours later
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    public function identify(string $rawBody, array $headers): array
    {
        $json = json_decode($rawBody, true);
        is_array($json) && isset($json['id']) || throw new InvalidWebhook('Not a Stripe event.', 'INVALID_PAYLOAD');

        return ['id' => (string) $json['id'], 'type' => $json['type'] ?? null];
    }

    public function interpret(string $rawBody, array $headers): PaymentNotice
    {
        $event = json_decode($rawBody, true);
        $type = (string) ($event['type'] ?? '');
        $object = (array) ($event['data']['object'] ?? []);
        $eventId = (string) ($event['id'] ?? '');

        return match (true) {
            str_starts_with($type, 'payment_intent.') => $this->intentNotice($object, $type, $eventId),
            $type === 'charge.refunded' => new PaymentNotice(
                PaymentNotice::KIND_REFUND, $object['metadata']['payment_id'] ?? null, $object['payment_intent'] ?? null, amount: null,
                currency: strtoupper((string) ($object['currency'] ?? '')), eventId: $eventId, refundTotal: MinorUnits::toAmount((int) ($object['amount_refunded'] ?? 0), $this->decimals((string) ($object['currency'] ?? ''))), raw: $object,
            ),
            $type === 'charge.dispute.created' => new PaymentNotice(PaymentNotice::KIND_DISPUTE, null, $object['payment_intent'] ?? null, eventId: $eventId, raw: $object),
            $type === 'charge.dispute.closed' => new PaymentNotice(PaymentNotice::KIND_DISPUTE, null, $object['payment_intent'] ?? null, eventId: $eventId, disputeWon: ($object['status'] ?? '') === 'won', raw: $object),
            default => PaymentNotice::ignore("event {$type} is not used", $eventId),
        };
    }

    public function testConnection(): string
    {
        $response = $this->http->send(Provider::STRIPE, $this->mode, 'GET', self::BASE.'/v1/balance', $this->headers());
        $response->successful() || $this->http->refuse($response, 'Stripe', 'INVALID_CREDENTIALS');
        $live = (bool) $response->json('livemode');
        $live === ($this->mode === Provider::LIVE) || throw new PaymentError('These Stripe keys belong to the '.($live ? 'live' : 'test').' environment, not '.$this->mode.'.', 'CREDENTIAL_ENVIRONMENT_MISMATCH');

        return 'Stripe: credentials valid ('.($live ? 'live' : 'test').' mode).';
    }

    public function publicConfig(): array
    {
        return array_filter(['publishable_key' => $this->credentials['publishable_key'] ?? null]);
    }

    /** @param array<string, mixed> $intent */
    private function intentNotice(array $intent, string $type = '', string $eventId = ''): PaymentNotice
    {
        $status = $this->status((string) ($intent['status'] ?? ''));
        $error = (array) ($intent['last_payment_error'] ?? []);
        $currency = strtoupper((string) ($intent['currency'] ?? ''));
        // A declined card attempt is not the end of the intent: the shopper can try another card on the same intent.
        if ($type === 'payment_intent.payment_failed') {
            $status = S::REQUIRES_ACTION;
        }

        return new PaymentNotice(
            PaymentNotice::KIND_STATUS, $intent['metadata']['payment_id'] ?? null, $intent['id'] ?? null, $status,
            $status === S::CAPTURED ? MinorUnits::toAmount((int) ($intent['amount_received'] ?? $intent['amount'] ?? 0), $this->decimals($currency)) : null,
            $currency, $eventId ?: null, failureCode: $error['decline_code'] ?? $error['code'] ?? null, failureMessage: $error['message'] ?? null, raw: $intent,
        );
    }

    private function status(string $stripe): string
    {
        return match ($stripe) {
            'succeeded' => S::CAPTURED,
            'processing', 'requires_capture' => S::PROCESSING,
            'canceled' => S::CANCELLED,
            default => S::REQUIRES_ACTION, // requires_payment_method, requires_confirmation, requires_action
        };
    }

    private function decimals(string $currency): int
    {
        return in_array(strtoupper($currency), ['JPY', 'KRW', 'CLP', 'VND'], true) ? 0 : 2;
    }

    /** @param array<string, string> $extra @return array<string, string> */
    private function headers(array $extra = []): array
    {
        return ['Authorization' => 'Bearer '.($this->credentials['secret_key'] ?? ''), 'Stripe-Version' => '2024-06-20'] + $extra;
    }
}
