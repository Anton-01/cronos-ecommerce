<?php

declare(strict_types=1);

namespace Cronos\Checkout\Interfaces\GraphQL;

use Cronos\Checkout\Application\CartPricer;
use Cronos\Checkout\Application\CartService;
use Cronos\Checkout\Domain\Exceptions\CheckoutError;
use Cronos\Checkout\Domain\Ports\CartRepository;
use Cronos\Pricing\Domain\Exceptions\InvalidPricing;
use Cronos\Promotions\Domain\Exceptions\InvalidCoupon;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class CartResolver
{
    public function __construct(
        private readonly CartService $carts,
        private readonly CartPricer $pricer,
        private readonly CartRepository $rows,
    ) {}

    /** @param array<string, mixed> $args */
    public function open(mixed $root, array $args, GraphQLContext $context): array
    {
        $session = $this->carts->resolve($this->userId($context), $args['token'] ?? null, $args['market']);

        return ['cart' => $this->present($session['cart'], $context), 'token' => $session['token']];
    }

    /** @param array<string, mixed> $args */
    public function cart(mixed $root, array $args, GraphQLContext $context): array
    {
        return $this->present($this->owned($args, $context), $context);
    }

    /** @param array<string, mixed> $args */
    public function setItem(mixed $root, array $args, GraphQLContext $context): array
    {
        $cart = $this->owned($args, $context);
        $this->carts->setItem($cart['id'], $args['productId'], $args['quantity'], (bool) ($args['byRoll'] ?? false));

        return $this->present($this->rows->find($cart['id']), $context);
    }

    /** @param array<string, mixed> $args */
    public function removeItem(mixed $root, array $args, GraphQLContext $context): array
    {
        $cart = $this->owned($args, $context);
        $this->carts->removeItem($cart['id'], $args['productId']);

        return $this->present($this->rows->find($cart['id']), $context);
    }

    /** @param array<string, mixed> $args */
    public function coupon(mixed $root, array $args, GraphQLContext $context): array
    {
        $cart = $this->owned($args, $context);
        $this->carts->setCoupon($cart['id'], $args['code'] ?? null);

        return $this->present($this->rows->find($cart['id']), $context);
    }

    /** @param array<string, mixed> $args */
    public function market(mixed $root, array $args, GraphQLContext $context): array
    {
        $cart = $this->owned($args, $context);
        $this->carts->setMarket($cart['id'], $args['market'], $args['region'] ?? null);

        return $this->present($this->rows->find($cart['id']), $context);
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function owned(array $args, GraphQLContext $context): array
    {
        return $this->carts->authorize($args['cartId'] ?? $args['id'], $this->userId($context), $args['token'] ?? null);
    }

    /** @param array<string, mixed> $cart */
    private function present(array $cart, GraphQLContext $context): array
    {
        $items = $this->rows->items($cart['id']);
        $out = ['id' => $cart['id'], 'market' => $cart['market_code'], 'region' => $cart['region'], 'couponCode' => $cart['coupon_code'], 'couponError' => null, 'lines' => [], 'totals' => null, 'warning' => null];
        if ($items === []) {
            return $out;
        }

        try {
            try {
                $priced = $this->pricer->price($cart['market_code'], $cart['region'], $items, $cart['coupon_code'], $this->userId($context));
            } catch (InvalidCoupon $e) {
                $out['couponError'] = $e->getMessage();
                $priced = $this->pricer->price($cart['market_code'], $cart['region'], $items, null, $this->userId($context));
            }
        } catch (CheckoutError $e) {
            $out['warning'] = $e->getMessage();

            return $out;
        } catch (InvalidPricing $e) {
            $out['warning'] = $e->getMessage();

            return $out;
        }

        return ['lines' => array_map(Present::line(...), $priced['lines']), 'totals' => Present::totals($priced), 'couponCode' => $priced['coupon_code'] ?? $cart['coupon_code']] + $out;
    }

    private function userId(GraphQLContext $context): ?string
    {
        $id = $context->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }
}
