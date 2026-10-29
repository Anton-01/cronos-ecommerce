<?php

declare(strict_types=1);

namespace Cronos\Payments\Infrastructure\Gateways;

use Cronos\Payments\Domain\Exceptions\InvalidWebhook;
use Cronos\Payments\Domain\Exceptions\PaymentError;
use Cronos\Payments\Domain\Ports\PaymentGateway;
use Cronos\Payments\Domain\ValueObjects\GatewayPayment;
use Cronos\Payments\Domain\ValueObjects\PaymentNotice;
use Cronos\Payments\Domain\ValueObjects\PaymentRequest;
use Cronos\Payments\Domain\ValueObjects\PaymentStatus as S;
use Cronos\Payments\Domain\ValueObjects\Provider;
use Cronos\Payments\Domain\ValueObjects\RefundResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * PayPal Orders v2 with intent CAPTURE: the shopper approves on PayPal, then we capture. The capture is idempotent
 * (PayPal-Request-Id), so the return-URL handler and the CHECKOUT.ORDER.APPROVED webhook can both try without double charging.
 */
final class PayPalGateway implements PaymentGateway
{
    /** @param array<string, string> $credentials */
    public function __construct(private readonly GatewayHttp $http, private readonly array $credentials, private readonly string $mode) {}

    public function provider(): string
    {
        return Provider::PAYPAL;
    }

    public function create(string $paymentId, PaymentRequest $request): GatewayPayment
    {
        $unit = [
            'reference_id' => $paymentId, 'custom_id' => $paymentId, 'invoice_id' => $paymentId,
            'description' => mb_substr($request->description ?? 'Pedido', 0, 120),
            'amount' => ['currency_code' => $request->amount->currency, 'value' => $request->amount->value()],
        ];
        $body = ['intent' => 'CAPTURE', 'purchase_units' => [$unit]];
        $context = array_filter(['return_url' => $request->returnUrl, 'cancel_url' => $request->cancelUrl, 'user_action' => 'PAY_NOW', 'shipping_preference' => 'NO_SHIPPING']);
        $body['payment_source'] = ['paypal' => ['experience_context' => $context]];
        $request->customerEmail === null || $body['payment_source']['paypal']['email_address'] = $request->customerEmail;

        $response = $this->call('POST', '/v2/checkout/orders', ['PayPal-Request-Id' => 'order-'.$paymentId, 'Prefer' => 'return=representation'], json: $body);
        $response->successful() || $this->http->refuse($response, 'PayPal');

        $links = collect((array) $response->json('links'));
        $url = $links->firstWhere('rel', 'payer-action')['href'] ?? $links->firstWhere('rel', 'approve')['href'] ?? null;

        return new GatewayPayment((string) $response->json('id'), S::REQUIRES_ACTION, ['type' => 'redirect', 'url' => $url, 'client_id' => $this->credentials['client_id'] ?? null], (array) $response->json());
    }

    public function fetch(array $payment): ?PaymentNotice
    {
        $orderId = $this->orderId($payment);
        if ($orderId === null) {
            return null;
        }
        $response = $this->call('GET', "/v2/checkout/orders/{$orderId}");
        $response->successful() || $this->http->refuse($response, 'PayPal');

        return $this->orderNotice((array) $response->json(), $payment['id']);
    }

    public function finalize(array $payment): PaymentNotice
    {
        $orderId = $this->orderId($payment) ?? throw new PaymentError('This payment has no PayPal order yet.', 'NO_REFERENCE');
        $response = $this->call('POST', "/v2/checkout/orders/{$orderId}/capture", ['PayPal-Request-Id' => 'capture-'.$payment['id'], 'Prefer' => 'return=representation'], json: (object) []);

        if ($response->status() === 422) {
            $issue = (string) $response->json('details.0.issue');
            if ($issue === 'ORDER_ALREADY_CAPTURED') {
                return $this->fetch($payment) ?? PaymentNotice::ignore('captured elsewhere');
            }
            if ($issue === 'ORDER_NOT_APPROVED') {
                return new PaymentNotice(PaymentNotice::KIND_STATUS, $payment['id'], $orderId, S::REQUIRES_ACTION, raw: (array) $response->json()); // the shopper has not approved yet
            }
        }
        $response->successful() || $this->http->refuse($response, 'PayPal', 'CAPTURE_REFUSED');

        return $this->orderNotice((array) $response->json(), $payment['id']);
    }

    public function refund(array $payment, string $amount, string $idempotencyKey, ?string $reason): RefundResult
    {
        $captureId = $payment['provider_reference'];
        $body = ['amount' => ['value' => $amount, 'currency_code' => $payment['currency']]];
        $reason === null || $body['note_to_payer'] = mb_substr($reason, 0, 255);

        $response = $this->call('POST', "/v2/payments/captures/{$captureId}/refund", ['PayPal-Request-Id' => 'refund-'.$idempotencyKey, 'Prefer' => 'return=representation'], json: $body);
        $response->successful() || $this->http->refuse($response, 'PayPal', 'REFUND_REFUSED');

        $status = (string) $response->json('status');

        return new RefundResult((string) $response->json('id'), $status === 'COMPLETED' ? 'succeeded' : (in_array($status, ['FAILED', 'CANCELLED'], true) ? 'failed' : 'pending'), (array) $response->json());
    }

    /** PayPal's signature is checked by PayPal itself (a network call), so it happens in the queue. */
    public function verifiesLocally(): bool
    {
        return false;
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $webhookId = $this->credentials['webhook_id'] ?? '';
        $event = json_decode($rawBody, true);
        if ($webhookId === '' || ! is_array($event) || ($headers['paypal-transmission-sig'] ?? '') === '') {
            return false;
        }

        $response = $this->call('POST', '/v1/notifications/verify-webhook-signature', json: [
            'auth_algo' => $headers['paypal-auth-algo'] ?? '', 'cert_url' => $headers['paypal-cert-url'] ?? '', 'transmission_id' => $headers['paypal-transmission-id'] ?? '',
            'transmission_sig' => $headers['paypal-transmission-sig'] ?? '', 'transmission_time' => $headers['paypal-transmission-time'] ?? '', 'webhook_id' => $webhookId, 'webhook_event' => $event,
        ]);

        return $response->successful() && $response->json('verification_status') === 'SUCCESS';
    }

    public function identify(string $rawBody, array $headers): array
    {
        $json = json_decode($rawBody, true);
        is_array($json) && isset($json['id']) || throw new InvalidWebhook('Not a PayPal event.', 'INVALID_PAYLOAD');

        return ['id' => (string) $json['id'], 'type' => $json['event_type'] ?? null];
    }

    public function interpret(string $rawBody, array $headers): PaymentNotice
    {
        $event = (array) json_decode($rawBody, true);
        $type = (string) ($event['event_type'] ?? '');
        $r = (array) ($event['resource'] ?? []);
        $eventId = (string) ($event['id'] ?? '');

        return match ($type) {
            'CHECKOUT.ORDER.APPROVED' => new PaymentNotice(PaymentNotice::KIND_STATUS, $r['purchase_units'][0]['custom_id'] ?? null, $r['id'] ?? null, S::REQUIRES_ACTION, eventId: $eventId, needsCapture: true, raw: $r),
            'PAYMENT.CAPTURE.COMPLETED' => new PaymentNotice(
                PaymentNotice::KIND_STATUS, $r['custom_id'] ?? null, $r['id'] ?? null, S::CAPTURED, (string) ($r['amount']['value'] ?? ''), (string) ($r['amount']['currency_code'] ?? ''), $eventId, raw: $r,
            ),
            'PAYMENT.CAPTURE.PENDING' => new PaymentNotice(PaymentNotice::KIND_STATUS, $r['custom_id'] ?? null, $r['id'] ?? null, S::PROCESSING, eventId: $eventId, raw: $r),
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED' => new PaymentNotice(PaymentNotice::KIND_STATUS, $r['custom_id'] ?? null, $r['id'] ?? null, S::FAILED, eventId: $eventId, failureCode: 'CAPTURE_DENIED', failureMessage: 'PayPal denied the capture.', raw: $r),
            'PAYMENT.CAPTURE.REFUNDED' => new PaymentNotice(
                PaymentNotice::KIND_REFUND, $r['custom_id'] ?? null, $this->captureIdFromLinks($r), amount: (string) ($r['amount']['value'] ?? ''), currency: (string) ($r['amount']['currency_code'] ?? ''),
                eventId: $eventId, refundReference: $r['id'] ?? null, raw: $r,
            ),
            'PAYMENT.CAPTURE.REVERSED' => new PaymentNotice(PaymentNotice::KIND_DISPUTE, $r['custom_id'] ?? null, $r['id'] ?? null, eventId: $eventId, raw: $r),
            'CUSTOMER.DISPUTE.CREATED', 'CUSTOMER.DISPUTE.RESOLVED' => new PaymentNotice(
                PaymentNotice::KIND_DISPUTE, null, $r['disputed_transactions'][0]['seller_transaction_id'] ?? null, eventId: $eventId,
                disputeWon: $type === 'CUSTOMER.DISPUTE.RESOLVED' && ($r['dispute_outcome']['outcome_code'] ?? '') === 'RESOLVED_SELLER_FAVOUR', raw: $r,
            ),
            default => PaymentNotice::ignore("event {$type} is not used", $eventId),
        };
    }

    public function testConnection(): string
    {
        $this->token(fresh: true);

        return "PayPal: credentials valid ({$this->mode}).";
    }

    public function publicConfig(): array
    {
        return array_filter(['client_id' => $this->credentials['client_id'] ?? null]);
    }

    /** @param array<string, mixed> $order */
    private function orderNotice(array $order, string $paymentId): PaymentNotice
    {
        $capture = (array) ($order['purchase_units'][0]['payments']['captures'][0] ?? []);
        $status = (string) ($order['status'] ?? '');
        $captureStatus = (string) ($capture['status'] ?? '');

        if ($captureStatus === 'COMPLETED') {
            return new PaymentNotice(PaymentNotice::KIND_STATUS, $paymentId, (string) $capture['id'], S::CAPTURED, (string) $capture['amount']['value'], (string) $capture['amount']['currency_code'], raw: $order);
        }
        if (in_array($captureStatus, ['DECLINED', 'FAILED'], true)) {
            return new PaymentNotice(PaymentNotice::KIND_STATUS, $paymentId, (string) ($capture['id'] ?? $order['id'] ?? ''), S::FAILED, failureCode: 'CAPTURE_DENIED', failureMessage: 'PayPal declined the capture.', raw: $order);
        }
        if ($captureStatus === 'PENDING') {
            return new PaymentNotice(PaymentNotice::KIND_STATUS, $paymentId, (string) $capture['id'], S::PROCESSING, raw: $order);
        }

        return new PaymentNotice(PaymentNotice::KIND_STATUS, $paymentId, (string) ($order['id'] ?? ''), $status === 'VOIDED' ? S::CANCELLED : S::REQUIRES_ACTION, needsCapture: $status === 'APPROVED', raw: $order);
    }

    /** @param array<string, mixed> $payment */
    private function orderId(array $payment): ?string
    {
        // After the capture, provider_reference holds the capture id and the order id is kept in the metadata.
        return $payment['metadata']['initial_reference'] ?? $payment['provider_reference'];
    }

    /** @param array<string, mixed> $resource */
    private function captureIdFromLinks(array $resource): ?string
    {
        foreach ((array) ($resource['links'] ?? []) as $link) {
            if (($link['rel'] ?? '') === 'up' && preg_match('#/captures/([A-Z0-9]+)#', (string) $link['href'], $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /** @param array<string, string> $headers @param array<string, mixed>|object|null $json */
    private function call(string $method, string $path, array $headers = [], array|object|null $json = null): Response
    {
        $response = $this->http->send(Provider::PAYPAL, $this->mode, $method, $this->base().$path, ['Authorization' => 'Bearer '.$this->token()] + $headers, json: $json === null ? null : (array) $json, safeToRetry: $method === 'GET' || isset($headers['PayPal-Request-Id']));
        if ($response->status() === 401) { // the cached token expired early: get a new one once
            $response = $this->http->send(Provider::PAYPAL, $this->mode, $method, $this->base().$path, ['Authorization' => 'Bearer '.$this->token(fresh: true)] + $headers, json: $json === null ? null : (array) $json, safeToRetry: $method === 'GET' || isset($headers['PayPal-Request-Id']));
        }

        return $response;
    }

    private function token(bool $fresh = false): string
    {
        $key = 'paypal:token:'.$this->mode.':'.substr(hash('sha256', ($this->credentials['client_id'] ?? '').($this->credentials['client_secret'] ?? '')), 0, 16);
        $fresh && Cache::forget($key);

        return Cache::remember($key, 1500, function (): string {
            $response = $this->http->send(Provider::PAYPAL, $this->mode, 'POST', $this->base().'/v1/oauth2/token', ['Accept' => 'application/json'], form: ['grant_type' => 'client_credentials'], basic: [$this->credentials['client_id'] ?? '', $this->credentials['client_secret'] ?? '']);
            $response->successful() || $this->http->refuse($response, 'PayPal', 'INVALID_CREDENTIALS');

            return (string) $response->json('access_token');
        });
    }

    private function base(): string
    {
        return $this->mode === Provider::LIVE ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }
}
