<?php

declare(strict_types=1);

namespace Cronos\Checkout\Interfaces\GraphQL;

use Cronos\Checkout\Application\CartService;
use Cronos\Checkout\Application\CheckoutService;
use Cronos\Checkout\Domain\Exceptions\CheckoutError;
use Cronos\Payments\Application\PaymentService;
use Cronos\Payments\Domain\Ports\PaymentRepository;
use Cronos\Payments\Domain\ValueObjects\PaymentStatus as S;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class CheckoutResolver
{
    use ResolvesClientContext;

    public function __construct(
        private readonly CheckoutService $checkouts,
        private readonly CartService $carts,
        private readonly PaymentService $payments,
        private readonly PaymentRepository $paymentRows,
    ) {}

    /** @param array<string, mixed> $args */
    public function start(mixed $root, array $args, GraphQLContext $context): array
    {
        $in = $args['input'];
        $userId = $this->userId($context);
        $cart = $this->carts->authorize($in['cartId'], $userId, $in['token'] ?? null);

        $checkout = $this->checkouts->start(
            $cart['id'], $userId, $in['email'], $this->address($in['shippingAddress']), isset($in['billingAddress']) ? $this->address($in['billingAddress']) : null,
            $in['idempotencyKey'], $this->clientContext($context),
        );

        return $this->present($checkout);
    }

    /** @param array<string, mixed> $args */
    public function checkout(mixed $root, array $args, GraphQLContext $context): array
    {
        return $this->present($this->owned($args, $context));
    }

    /** @param array<string, mixed> $args */
    public function options(mixed $root, array $args, GraphQLContext $context): array
    {
        $checkout = $this->owned($args, $context);

        return $this->checkouts->paymentOptions($checkout['id']);
    }

    /** @param array<string, mixed> $args */
    public function pay(mixed $root, array $args, GraphQLContext $context): array
    {
        $checkout = $this->owned($args, $context);
        $this->assertReturnUrl($args['returnUrl']);
        $this->assertReturnUrl($args['cancelUrl']);

        $r = $this->checkouts->pay($checkout['id'], $args['provider'], $args['returnUrl'], $args['cancelUrl'], $this->userId($context), $this->clientContext($context));

        return [
            'paymentId' => $r['payment']['id'], 'provider' => $r['payment']['provider'], 'mode' => $r['payment']['mode'], 'status' => $r['payment']['status'],
            'clientAction' => $r['client_action'], 'publicConfig' => $r['public'],
        ];
    }

    /** @param array<string, mixed> $args */
    public function confirm(mixed $root, array $args, GraphQLContext $context): array
    {
        $checkout = $this->owned($args, $context);
        $payments = $this->paymentRows->forPayable('checkout', $checkout['id']);
        $latest = end($payments) ?: null;
        if ($latest !== null && S::isOpen($latest['status'])) {
            $this->payments->finalize($latest['id'], $this->userId($context));
        }

        return $this->present($this->checkouts->require($checkout['id']));
    }

    /** @param array<string, mixed> $args */
    public function cancel(mixed $root, array $args, GraphQLContext $context): array
    {
        $checkout = $this->owned($args, $context);
        $this->checkouts->cancel($checkout['id'], $this->userId($context));

        return $this->present($this->checkouts->require($checkout['id']));
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function owned(array $args, GraphQLContext $context): array
    {
        $checkout = $this->checkouts->require($args['id']);
        $this->carts->authorize($checkout['cart_id'], $this->userId($context), $args['token'] ?? null); // same answer for "not yours" and "does not exist"

        return $checkout;
    }

    /** @param array<string, mixed> $c @return array<string, mixed> */
    private function present(array $c): array
    {
        return [
            'id' => $c['id'], 'status' => $c['status'], 'email' => $c['email'], 'marketCode' => $c['market_code'], 'currency' => $c['currency'], 'taxIncluded' => $c['tax_included'],
            'lines' => array_map(Present::line(...), $c['lines']), 'totals' => Present::totals($c), 'couponCode' => $c['coupon_code'],
            'expiresAt' => $c['expires_at'], 'paidAt' => $c['paid_at'], 'paymentId' => $c['payment_id'],
        ];
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private function address(array $a): array
    {
        return [
            'recipient_name' => $a['recipientName'], 'phone' => $a['phone'] ?? null, 'street' => $a['street'], 'exterior_no' => $a['exteriorNo'] ?? null,
            'interior_no' => $a['interiorNo'] ?? null, 'neighborhood' => $a['neighborhood'] ?? null, 'city' => $a['city'], 'state' => $a['state'],
            'country_code' => strtoupper($a['countryCode']), 'zip' => $a['zip'], 'references' => $a['references'] ?? null,
        ];
    }

    /** The provider sends the shopper back to us: only to our own storefront, never to a URL a caller chose. */
    private function assertReturnUrl(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        $own = parse_url((string) config('app.storefront_url'), PHP_URL_HOST);
        (is_string($host) && $host === $own && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))
            || throw new CheckoutError('The return address must belong to the store.', 'INVALID_RETURN_URL');
    }

    private function userId(GraphQLContext $context): ?string
    {
        $id = $context->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }
}
