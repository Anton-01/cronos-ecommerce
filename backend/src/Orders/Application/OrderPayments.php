<?php

declare(strict_types=1);

namespace Cronos\Orders\Application;

use Brick\Math\BigDecimal;
use Cronos\Orders\Domain\Exceptions\OrderError;
use Cronos\Orders\Domain\Ports\OrderRepository;
use Cronos\Payments\Application\PaymentService;
use Cronos\Payments\Application\ProviderCatalog;
use Cronos\Payments\Domain\Ports\PaymentRepository;
use Cronos\Payments\Domain\Ports\ProviderRepository;
use Cronos\Payments\Domain\ValueObjects\PaymentRequest;
use Cronos\Payments\Domain\ValueObjects\PaymentStatus as S;
use Cronos\Pricing\Domain\ValueObjects\Money;
use Cronos\Shared\Domain\ClientContext;

/** Collects the money of an order that was created before being paid (custom orders). The same payment machinery as checkout. */
final class OrderPayments
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly PaymentService $payments,
        private readonly PaymentRepository $paymentRows,
        private readonly ProviderRepository $providers,
    ) {}

    /** @param array<string, mixed> $order @return list<array{provider: string, name: string, mode: string}> */
    public function options(array $order): array
    {
        $out = [];
        foreach ($this->providers->all() as $p) {
            $fits = $p['is_enabled'] && in_array($order['market_code'], $p['markets'], true)
                && ($p['min_amount'] === null || BigDecimal::of($order['grand_total'])->isGreaterThanOrEqualTo($p['min_amount']))
                && ($p['max_amount'] === null || BigDecimal::of($order['grand_total'])->isLessThanOrEqualTo($p['max_amount']));
            $fits && $out[] = ['provider' => $p['code'], 'name' => ProviderCatalog::NAMES[$p['code']] ?? $p['code'], 'mode' => $p['mode']];
        }

        return $out;
    }

    /** @param array<string, mixed> $order @return array{payment: array<string, mixed>, client_action: ?array<string, mixed>, public: array<string, string>} */
    public function pay(array $order, string $provider, ?string $returnUrl, ?string $cancelUrl, ?string $userId, ?ClientContext $client): array
    {
        ($order['status'] === 'pending' && $order['payment_status'] === 'unpaid') || throw new OrderError('This order is not waiting for payment.', 'ORDER_NOT_PAYABLE');

        foreach ($this->paymentRows->forPayable('order', $order['id']) as $other) {
            if ($other['provider'] !== $provider && S::isOpen($other['status'])) {
                $this->payments->cancel($other['id'], 'The customer chose another payment method.');
            }
        }

        return $this->payments->start(new PaymentRequest(
            $provider, 'order', $order['id'], new Money($order['grand_total'], $order['currency']), $order['market_code'], "order:{$order['id']}:{$provider}",
            $order['email'], 'Order '.$order['order_number'], $returnUrl, $cancelUrl, ['order_id' => $order['id']],
        ), $client, $userId);
    }
}
