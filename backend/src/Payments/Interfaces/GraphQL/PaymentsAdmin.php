<?php

declare(strict_types=1);

namespace Cronos\Payments\Interfaces\GraphQL;

use Cronos\Checkout\Domain\Ports\CheckoutRepository;
use Cronos\Payments\Application\PaymentService;
use Cronos\Payments\Application\ProviderCatalog;
use Cronos\Payments\Application\ProviderManager;
use Cronos\Payments\Application\WebhookProcessor;
use Cronos\Payments\Domain\Exceptions\NotFound;
use Cronos\Payments\Domain\Ports\PaymentRepository;
use Cronos\Payments\Domain\Ports\ProviderRepository;
use Cronos\Payments\Domain\Ports\WebhookInbox;
use Cronos\Payments\Infrastructure\Jobs\ProcessWebhookJob;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/** Back-office payments: provider setup (sandbox/live), payment ledger, refunds and the webhook inbox. */
final class PaymentsAdmin
{
    use ResolvesClientContext;

    private const MAX_PAGE = 100;

    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly ProviderManager $manager,
        private readonly PaymentRepository $payments,
        private readonly PaymentService $service,
        private readonly WebhookInbox $inbox,
        private readonly WebhookProcessor $processor,
        private readonly CheckoutRepository $checkouts,
    ) {}

    public function providers(): array
    {
        return array_map($this->provider(...), $this->providers->all());
    }

    /** @param array<string, mixed> $args */
    public function configure(mixed $root, array $args): array
    {
        $in = $args['input'];
        $settings = [];
        foreach (['isEnabled' => 'is_enabled', 'markets' => 'markets', 'minAmount' => 'min_amount', 'maxAmount' => 'max_amount'] as $graph => $key) {
            array_key_exists($graph, $in) && $settings[$key] = $in[$graph];
        }
        $this->manager->configure($args['code'], $settings);

        return $this->providerByCode($args['code']);
    }

    /** @param array<string, mixed> $args */
    public function saveCredentials(mixed $root, array $args): array
    {
        is_array($args['values']) || throw new NotFound('The credentials');
        $this->manager->saveCredentials($args['code'], $args['mode'], $args['values']);

        return $this->providerByCode($args['code']);
    }

    /** @param array<string, mixed> $args */
    public function test(mixed $root, array $args): string
    {
        return $this->manager->testConnection($args['code'], $args['mode']);
    }

    /** @param array<string, mixed> $args */
    public function switchMode(mixed $root, array $args, GraphQLContext $context): array
    {
        $this->manager->switchMode($args['code'], $args['target'], $args['confirmation'] ?? null, $context->user()?->getAuthIdentifier());

        return $this->providerByCode($args['code']);
    }

    /** @param array<string, mixed> $args */
    public function payments(mixed $root, array $args): array
    {
        $page = $this->payments->search($args['status'] ?? null, $args['provider'] ?? null, $args['mode'] ?? null, $args['search'] ?? null, (bool) ($args['anomaliesOnly'] ?? false), $this->limit($args), max(0, (int) ($args['offset'] ?? 0)));

        return ['total' => $page['total'], 'rows' => array_map($this->presentPayment(...), $page['rows'])];
    }

    /** @param array<string, mixed> $args */
    public function payment(mixed $root, array $args): ?array
    {
        $row = $this->payments->find($args['id']);

        return $row === null ? null : $this->presentPayment($row);
    }

    /** @param array<string, mixed> $args */
    public function sync(mixed $root, array $args, GraphQLContext $context): array
    {
        $this->service->sync($args['id'], $context->user()?->getAuthIdentifier());

        return $this->presentPayment($this->payments->find($args['id']) ?? throw new NotFound('The payment'));
    }

    /** @param array<string, mixed> $args */
    public function refund(mixed $root, array $args, GraphQLContext $context): array
    {
        return $this->presentRefund($this->service->refund($args['paymentId'], $args['amount'], $args['reason'] ?? null, $args['idempotencyKey'], $context->user()?->getAuthIdentifier(), $this->clientContext($context)));
    }

    /** @param array<string, mixed> $args */
    public function webhookEvents(mixed $root, array $args): array
    {
        $page = $this->inbox->search($args['status'] ?? null, $args['provider'] ?? null, $this->limit($args), max(0, (int) ($args['offset'] ?? 0)));

        return ['total' => $page['total'], 'rows' => array_map($this->presentEvent(...), $page['rows'])];
    }

    /** @param array<string, mixed> $args */
    public function reprocessWebhook(mixed $root, array $args): array
    {
        $this->processor->reset($args['id']);
        ProcessWebhookJob::dispatch($args['id']);

        return $this->presentEvent($this->inbox->find($args['id']) ?? throw new NotFound('The notification'));
    }

    /** @param array<string, mixed> $args */
    public function reprocessFailed(mixed $root, array $args): int
    {
        $n = 0;
        foreach ($this->inbox->failedIds(max(1, min((int) ($args['limit'] ?? 100), 500))) as $id) {
            $this->processor->reset($id);
            ProcessWebhookJob::dispatch($id);
            $n++;
        }

        return $n;
    }

    /** @param array<string, mixed> $args */
    public function checkouts(mixed $root, array $args): array
    {
        return array_map(static fn (array $c) => [
            'id' => $c['id'], 'email' => $c['email'], 'marketCode' => $c['market_code'], 'currency' => $c['currency'], 'status' => $c['status'], 'subtotal' => $c['subtotal'],
            'itemsDiscount' => $c['items_discount'], 'shipping' => $c['shipping'], 'tax' => $c['tax'], 'total' => $c['total'], 'couponCode' => $c['coupon_code'],
            'fraudScore' => (int) $c['fraud_score'], 'fraudDecision' => $c['fraud_decision'], 'paymentId' => $c['payment_id'], 'notes' => $c['notes'], 'lines' => $c['lines'],
            'expiresAt' => $c['expires_at'], 'paidAt' => $c['paid_at'], 'country' => $c['country_code'], 'createdAt' => $c['created_at'],
        ], $this->checkouts->search($args['status'] ?? null, $args['search'] ?? null, $this->limit($args), max(0, (int) ($args['offset'] ?? 0))));
    }

    /** @param array<string, mixed> $args */
    private function limit(array $args): int
    {
        return max(1, min((int) ($args['first'] ?? 25), self::MAX_PAGE));
    }

    /** @return array<string, mixed> */
    private function providerByCode(string $code): array
    {
        return $this->provider($this->providers->find($code) ?? throw new NotFound('The provider'));
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function provider(array $p): array
    {
        $fields = ProviderCatalog::FIELDS[$p['code']];
        $describe = static function (array $stored) use ($fields): array {
            $out = [];
            foreach ($fields as $key => $meta) {
                $value = (string) ($stored[$key] ?? '');
                $out[] = ['key' => $key, 'label' => $meta['label'], 'secret' => $meta['secret'], 'isSet' => $value !== '', 'display' => $value === '' ? null : ProviderCatalog::mask($value, $meta['secret'])];
            }

            return $out;
        };
        $live = $p['credentials']['live'];

        return [
            'code' => $p['code'], 'name' => $p['name'], 'isEnabled' => $p['is_enabled'], 'mode' => $p['mode'], 'markets' => $p['markets'],
            'minAmount' => $p['min_amount'], 'maxAmount' => $p['max_amount'], 'liveSince' => $p['live_since'],
            'sandbox' => $describe($p['credentials']['sandbox']), 'live' => $describe($live),
            'liveReady' => array_filter(ProviderCatalog::requiredFields($p['code']), static fn (string $f) => ($live[$f] ?? '') === '') === [],
            'webhookUrl' => rtrim((string) (config('payments.webhook_base_url') ?: config('app.url')), '/').'/webhooks/'.$p['code'],
        ];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function presentPayment(array $p): array
    {
        return [
            'id' => $p['id'], 'provider' => $p['provider'], 'mode' => $p['mode'], 'payableType' => $p['payable_type'], 'payableId' => $p['payable_id'], 'status' => $p['status'],
            'amount' => $p['amount'], 'currency' => $p['currency'], 'capturedAmount' => $p['captured_amount'], 'refundedAmount' => $p['refunded_amount'],
            'providerReference' => $p['provider_reference'], 'customerEmail' => $p['customer_email'], 'failureCode' => $p['failure_code'], 'failureMessage' => $p['failure_message'],
            'anomaly' => $p['anomaly'], 'ipAddress' => $p['ip_address'], 'country' => $p['country'], 'timezone' => $p['timezone'],
            'createdAt' => $p['created_at'], 'updatedAt' => $p['updated_at'],
            'transactions' => array_map(static fn (array $t) => [
                'id' => $t['id'], 'type' => $t['type'], 'status' => $t['status'], 'amount' => $t['amount'], 'providerReference' => $t['provider_reference'],
                'externalEventId' => $t['external_event_id'], 'durationMs' => $t['duration_ms'], 'error' => $t['error'], 'rawResponse' => $t['raw_response'],
                'country' => $t['country'], 'timezone' => $t['timezone'], 'createdAt' => $t['created_at'],
            ], $this->payments->transactions($p['id'])),
            'refunds' => array_map($this->presentRefund(...), $this->payments->refunds($p['id'])),
        ];
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private function presentRefund(array $r): array
    {
        return [
            'id' => $r['id'], 'paymentId' => $r['payment_id'], 'amount' => $r['amount'], 'currency' => $r['currency'], 'status' => $r['status'], 'reason' => $r['reason'],
            'providerReference' => $r['provider_reference'], 'failureMessage' => $r['failure_message'], 'createdAt' => $r['created_at'],
        ];
    }

    /** @param array<string, mixed> $e @return array<string, mixed> */
    private function presentEvent(array $e): array
    {
        return [
            'id' => $e['id'], 'provider' => $e['provider'], 'mode' => $e['mode'], 'eventId' => $e['event_id'], 'eventType' => $e['event_type'], 'signatureValid' => $e['signature_valid'],
            'status' => $e['status'], 'attempts' => $e['attempts'], 'lastError' => $e['last_error'], 'nextAttemptAt' => $e['next_attempt_at'], 'receivedAt' => $e['received_at'],
            'processedAt' => $e['processed_at'], 'paymentId' => $e['payment_id'], 'ip' => $e['ip'], 'rawBody' => $e['raw_body'], 'headers' => $e['headers'],
        ];
    }
}
