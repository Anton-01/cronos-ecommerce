<?php

declare(strict_types=1);

namespace Cronos\Payments\Infrastructure\Gateways;

use Cronos\Payments\Domain\Exceptions\InvalidWebhook;
use Cronos\Payments\Domain\Ports\PaymentGateway;
use Cronos\Payments\Domain\ValueObjects\GatewayPayment;
use Cronos\Payments\Domain\ValueObjects\PaymentNotice;
use Cronos\Payments\Domain\ValueObjects\PaymentRequest;
use Cronos\Payments\Domain\ValueObjects\PaymentStatus as S;
use Cronos\Payments\Domain\ValueObjects\Provider;
use Cronos\Payments\Domain\ValueObjects\RefundResult;

/**
 * Mercado Pago Checkout Pro: we create a preference (our payment id travels as `external_reference`), the shopper pays on Mercado
 * Pago's page (cards, OXXO, SPEI...), and the payment id MP assigns reaches us by webhook, where it replaces the preference id.
 */
final class MercadoPagoGateway implements PaymentGateway
{
    private const BASE = 'https://api.mercadopago.com';

    private const SIGNATURE_TOLERANCE = 600;

    /** @param array<string, string> $credentials */
    public function __construct(private readonly GatewayHttp $http, private readonly array $credentials, private readonly string $mode, private readonly string $notificationUrl) {}

    public function provider(): string
    {
        return Provider::MERCADOPAGO;
    }

    public function create(string $paymentId, PaymentRequest $request): GatewayPayment
    {
        $amount = (float) $request->amount->value();
        $body = [
            'items' => [['id' => $request->payableId, 'title' => mb_substr($request->description ?? 'Pedido', 0, 120), 'quantity' => 1, 'currency_id' => $request->amount->currency, 'unit_price' => $amount]],
            'external_reference' => $paymentId,
            'notification_url' => $this->notificationUrl,
            'metadata' => ['payment_id' => $paymentId, 'payable_type' => $request->payableType, 'payable_id' => $request->payableId],
            'auto_return' => 'approved',
        ];
        $request->customerEmail === null || $body['payer'] = ['email' => $request->customerEmail];
        $request->returnUrl === null || $body['back_urls'] = ['success' => $request->returnUrl, 'pending' => $request->returnUrl, 'failure' => $request->cancelUrl ?? $request->returnUrl];

        $response = $this->http->send(Provider::MERCADOPAGO, $this->mode, 'POST', self::BASE.'/checkout/preferences', $this->headers(['X-Idempotency-Key' => 'pref-'.$paymentId]), json: $body);
        $response->successful() || $this->http->refuse($response, 'Mercado Pago');

        $url = $this->mode === Provider::SANDBOX ? ($response->json('sandbox_init_point') ?? $response->json('init_point')) : $response->json('init_point');

        return new GatewayPayment((string) $response->json('id'), S::REQUIRES_ACTION, ['type' => 'redirect', 'url' => $url, 'public_key' => $this->credentials['public_key'] ?? null], (array) $response->json());
    }

    public function fetch(array $payment): ?PaymentNotice
    {
        $response = $this->http->send(Provider::MERCADOPAGO, $this->mode, 'GET', self::BASE.'/v1/payments/search', $this->headers(), query: ['external_reference' => $payment['id'], 'sort' => 'date_created', 'criteria' => 'desc', 'limit' => 10]);
        $response->successful() || $this->http->refuse($response, 'Mercado Pago');

        $results = (array) $response->json('results', []);
        if ($results === []) {
            return null;
        }
        // The best outcome wins: an approved attempt is what matters even if a later one was rejected.
        usort($results, fn (array $a, array $b) => ($b['status'] === 'approved') <=> ($a['status'] === 'approved'));

        return $this->paymentNotice($results[0]);
    }

    public function finalize(array $payment): PaymentNotice
    {
        return $this->fetch($payment) ?? PaymentNotice::ignore('no payment made yet');
    }

    public function refund(array $payment, string $amount, string $idempotencyKey, ?string $reason): RefundResult
    {
        $mpPaymentId = $payment['provider_reference'];
        $response = $this->http->send(Provider::MERCADOPAGO, $this->mode, 'POST', self::BASE."/v1/payments/{$mpPaymentId}/refunds", $this->headers(['X-Idempotency-Key' => 'refund-'.$idempotencyKey]), json: ['amount' => (float) $amount]);
        $response->successful() || $this->http->refuse($response, 'Mercado Pago', 'REFUND_REFUSED');

        $status = (string) $response->json('status');

        return new RefundResult((string) $response->json('id'), in_array($status, ['approved', 'refunded'], true) ? 'succeeded' : ($status === 'rejected' ? 'failed' : 'pending'), (array) $response->json());
    }

    public function verifiesLocally(): bool
    {
        return true;
    }

    /**
     * Mercado Pago signs `id:<data.id>;request-id:<x-request-id>;ts:<ts>;` with HMAC-SHA256 (hex). `data.id` comes from the URL query
     * (the controller passes it as the pseudo-header `x-query-data-id`); parts that are absent are left out of the template.
     */
    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $secret = $this->credentials['webhook_secret'] ?? '';
        $signature = $headers['x-signature'] ?? '';
        if ($secret === '' || $signature === '') {
            return false;
        }

        $ts = null;
        $hash = null;
        foreach (explode(',', $signature) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            $k === 'ts' && $ts = $v;
            $k === 'v1' && $hash = $v;
        }
        if ($ts === null || $hash === null || ! ctype_digit($ts)) {
            return false;
        }
        // The timestamp is in milliseconds on current API versions and seconds on old ones.
        $seconds = strlen($ts) > 11 ? intdiv((int) $ts, 1000) : (int) $ts;
        if (abs(time() - $seconds) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        $dataId = $headers['x-query-data-id'] ?? (json_decode($rawBody, true)['data']['id'] ?? null);
        $manifest = ($dataId !== null ? 'id:'.strtolower((string) $dataId).';' : '').(($headers['x-request-id'] ?? '') !== '' ? 'request-id:'.$headers['x-request-id'].';' : '').'ts:'.$ts.';';

        return hash_equals(hash_hmac('sha256', $manifest, $secret), $hash);
    }

    public function identify(string $rawBody, array $headers): array
    {
        $json = json_decode($rawBody, true);
        is_array($json) || throw new InvalidWebhook('Not a Mercado Pago notification.', 'INVALID_PAYLOAD');
        $dataId = $headers['x-query-data-id'] ?? ($json['data']['id'] ?? null);
        $action = (string) ($json['action'] ?? $json['type'] ?? 'unknown');
        // MP resends the same notification id; a different action on the same payment is a different event.
        $id = isset($json['id']) ? 'n'.$json['id'] : 'p'.($dataId ?? hash('sha256', $rawBody)).':'.$action.':'.($json['date_created'] ?? '');

        return ['id' => $id, 'type' => $action];
    }

    public function interpret(string $rawBody, array $headers): PaymentNotice
    {
        $json = (array) json_decode($rawBody, true);
        $type = (string) ($json['type'] ?? $json['topic'] ?? '');
        $dataId = $headers['x-query-data-id'] ?? ($json['data']['id'] ?? null);
        if (! str_starts_with($type, 'payment') || $dataId === null) {
            return PaymentNotice::ignore("notification type {$type} is not used");
        }

        // Webhooks carry only an id: the facts come from the API (and from nowhere else).
        $response = $this->http->send(Provider::MERCADOPAGO, $this->mode, 'GET', self::BASE."/v1/payments/{$dataId}", $this->headers());
        if ($response->status() === 404) {
            return PaymentNotice::ignore("payment {$dataId} does not exist for this account");
        }
        $response->successful() || $this->http->refuse($response, 'Mercado Pago');

        return $this->paymentNotice((array) $response->json());
    }

    public function testConnection(): string
    {
        $response = $this->http->send(Provider::MERCADOPAGO, $this->mode, 'GET', self::BASE.'/users/me', $this->headers());
        $response->successful() || $this->http->refuse($response, 'Mercado Pago', 'INVALID_CREDENTIALS');
        $site = (string) $response->json('site_id');
        $name = (string) ($response->json('nickname') ?? $response->json('email') ?? 'account');

        return "Mercado Pago: credentials valid ({$name}, {$site}, {$this->mode}).";
    }

    public function publicConfig(): array
    {
        return array_filter(['public_key' => $this->credentials['public_key'] ?? null]);
    }

    /** @param array<string, mixed> $p a /v1/payments resource */
    private function paymentNotice(array $p): PaymentNotice
    {
        $paymentId = $p['external_reference'] ?? ($p['metadata']['payment_id'] ?? null);
        $reference = isset($p['id']) ? (string) $p['id'] : null;
        $currency = (string) ($p['currency_id'] ?? '');
        $refunded = (string) ($p['transaction_amount_refunded'] ?? '0');
        $status = (string) ($p['status'] ?? '');

        if ($status === 'refunded' || ($status === 'approved' && (float) $refunded > 0)) {
            return new PaymentNotice(PaymentNotice::KIND_REFUND, $paymentId, $reference, amount: null, currency: $currency, refundTotal: number_format((float) ($status === 'refunded' ? ($p['transaction_amount'] ?? $refunded) : $refunded), 2, '.', ''), raw: $p);
        }
        if (in_array($status, ['charged_back', 'in_mediation'], true)) {
            return new PaymentNotice(PaymentNotice::KIND_DISPUTE, $paymentId, $reference, currency: $currency, raw: $p);
        }

        return new PaymentNotice(
            PaymentNotice::KIND_STATUS, $paymentId, $reference, $this->status($status),
            $status === 'approved' ? number_format((float) ($p['transaction_amount'] ?? 0), 2, '.', '') : null, $currency, failureCode: $p['status_detail'] ?? null,
            failureMessage: in_array($status, ['rejected', 'cancelled'], true) ? (string) ($p['status_detail'] ?? $status) : null, raw: $p,
        );
    }

    private function status(string $mp): string
    {
        return match ($mp) {
            'approved' => S::CAPTURED,
            'authorized', 'in_process', 'pending' => S::PROCESSING,
            'rejected' => S::FAILED,
            'cancelled' => S::CANCELLED,
            default => S::REQUIRES_ACTION,
        };
    }

    /** @param array<string, string> $extra @return array<string, string> */
    private function headers(array $extra = []): array
    {
        return ['Authorization' => 'Bearer '.($this->credentials['access_token'] ?? '')] + $extra;
    }
}
