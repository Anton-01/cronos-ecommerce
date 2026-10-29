<?php

declare(strict_types=1);

namespace Cronos\Orders\Interfaces\GraphQL;

use Cronos\Checkout\Domain\Exceptions\CheckoutError;
use Cronos\Orders\Application\OrderPayments;
use Cronos\Orders\Domain\Exceptions\OrderError;
use Cronos\Orders\Domain\Ports\OrderRepository;
use Cronos\Payments\Application\PaymentService;
use Cronos\Payments\Domain\Ports\PaymentRepository;
use Cronos\Payments\Domain\ValueObjects\PaymentStatus as S;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class StorefrontOrders
{
    use ResolvesClientContext;

    private const MAX_PAGE = 50;

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderPresenter $present,
        private readonly OrderPayments $orderPayments,
        private readonly PaymentService $payments,
        private readonly PaymentRepository $paymentRows,
    ) {}

    /** @param array<string, mixed> $args */
    public function mine(mixed $root, array $args, GraphQLContext $context): array
    {
        $rows = $this->orders->forUser((string) $context->user()->getAuthIdentifier(), max(1, min((int) ($args['first'] ?? 10), self::MAX_PAGE)), max(0, (int) ($args['offset'] ?? 0)));

        return ['total' => $this->orders->countForUser((string) $context->user()->getAuthIdentifier()), 'rows' => array_map($this->present->customer(...), $rows)];
    }

    /** @param array<string, mixed> $args */
    public function mineByNumber(mixed $root, array $args, GraphQLContext $context): ?array
    {
        $order = $this->orders->findByNumber($args['number']);

        return $order !== null && $order['user_id'] === (string) $context->user()->getAuthIdentifier() ? $this->present->customer($order) : null;
    }

    /** @param array<string, mixed> $args */
    public function track(mixed $root, array $args): ?array
    {
        $order = $this->orders->findByNumber($args['number']);

        return $order !== null && hash_equals(strtolower($order['email']), strtolower(trim($args['email']))) ? $this->present->customer($order) : null;
    }

    /** @param array<string, mixed> $args */
    public function paymentOptions(mixed $root, array $args, GraphQLContext $context): array
    {
        return $this->orderPayments->options($this->mineOrFail($args['number'], $context));
    }

    /** @param array<string, mixed> $args */
    public function pay(mixed $root, array $args, GraphQLContext $context): array
    {
        $order = $this->mineOrFail($args['number'], $context);
        $this->assertReturnUrl($args['returnUrl']);
        $this->assertReturnUrl($args['cancelUrl']);
        $r = $this->orderPayments->pay($order, $args['provider'], $args['returnUrl'], $args['cancelUrl'], (string) $context->user()->getAuthIdentifier(), $this->clientContext($context));

        return ['paymentId' => $r['payment']['id'], 'provider' => $r['payment']['provider'], 'mode' => $r['payment']['mode'], 'status' => $r['payment']['status'], 'clientAction' => $r['client_action'], 'publicConfig' => $r['public']];
    }

    /** @param array<string, mixed> $args */
    public function confirm(mixed $root, array $args, GraphQLContext $context): array
    {
        $order = $this->mineOrFail($args['number'], $context);
        $payments = $this->paymentRows->forPayable('order', $order['id']);
        $latest = end($payments) ?: null;
        $latest !== null && S::isOpen($latest['status']) && $this->payments->finalize($latest['id'], (string) $context->user()->getAuthIdentifier());

        return $this->present->customer($this->orders->find($order['id']));
    }

    /** @return array<string, mixed> */
    private function mineOrFail(string $number, GraphQLContext $context): array
    {
        $order = $this->orders->findByNumber($number);
        ($order !== null && $order['user_id'] === (string) $context->user()->getAuthIdentifier()) || throw new OrderError('That order does not exist.', 'ORDER_NOT_FOUND');

        return $order;
    }

    /** The provider sends the shopper back to us: only to our own storefront. */
    private function assertReturnUrl(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        (is_string($host) && $host === parse_url((string) config('app.storefront_url'), PHP_URL_HOST) && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))
            || throw new CheckoutError('The return address must belong to the store.', 'INVALID_RETURN_URL');
    }
}
