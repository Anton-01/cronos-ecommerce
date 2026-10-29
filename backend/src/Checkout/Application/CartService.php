<?php

declare(strict_types=1);

namespace Cronos\Checkout\Application;

use Cronos\Catalog\Domain\Exceptions\InvalidSalesUnit;
use Cronos\Catalog\Domain\ValueObjects\SalesUnit;
use Cronos\Checkout\Domain\Exceptions\CheckoutError;
use Cronos\Checkout\Domain\Ports\CartRepository;
use Cronos\Checkout\Domain\Ports\SellableProducts;
use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * The shopper's basket. A guest holds an unguessable token (we store only its hash); on login the guest basket is merged into
 * the account basket. Quantities are validated against how each product is sold (pieces, free cut in steps, whole rolls).
 */
final class CartService
{
    public const MAX_LINES = 50;

    public function __construct(private readonly CartRepository $carts, private readonly SellableProducts $products) {}

    /**
     * The active cart of the account, or the guest cart behind the token, or a brand new one.
     *
     * @return array{cart: array<string, mixed>, token: ?string} token is only returned when a guest cart is created
     */
    public function resolve(?string $userId, ?string $token, string $marketCode): array
    {
        $market = strtoupper($marketCode);
        $this->products->marketCountry($market) !== null || throw new CheckoutError('That market does not exist.', 'UNKNOWN_MARKET');

        $guest = $token === null ? null : $this->carts->findActiveByTokenHash(self::hash($token));

        if ($userId !== null) {
            $mine = $this->carts->findActiveForUser($userId);
            if ($mine !== null && $guest !== null && $guest['id'] !== $mine['id']) {
                $this->carts->atomically(function () use ($mine, $guest): void {
                    $this->carts->mergeInto($mine['id'], $guest['id']);
                    $this->carts->update($guest['id'], ['status' => 'abandoned']);
                });
            } elseif ($mine === null && $guest !== null && $guest['user_id'] === null) {
                $this->carts->update($guest['id'], ['user_id' => $userId]); // the guest basket becomes the account basket
                $mine = $this->carts->find($guest['id']);
            }
            if ($mine !== null) {
                $this->touch($mine['id'], $market);

                return ['cart' => $this->carts->find($mine['id']), 'token' => null];
            }
        } elseif ($guest !== null) {
            $this->touch($guest['id'], $market);

            return ['cart' => $this->carts->find($guest['id']), 'token' => null];
        }

        $newToken = Str::random(48);
        $id = $this->carts->insert([
            'user_id' => $userId, 'token_hash' => self::hash($newToken), 'market_code' => $market,
            'expires_at' => $this->expiry()->format('Y-m-d H:i:sP'),
        ]);

        return ['cart' => $this->carts->find($id), 'token' => $userId === null ? $newToken : null];
    }

    /** Sets the line to this quantity (adds it when new). A quantity of zero removes it. */
    public function setItem(string $cartId, string $productId, string $quantity, bool $byRoll): void
    {
        $cart = $this->active($cartId);
        if (is_numeric($quantity) && (float) $quantity == 0.0) {
            $this->carts->removeItem($cartId, $productId);

            return;
        }
        $found = $this->products->find([$productId])[$productId] ?? throw new CheckoutError('That product is not available.', 'PRODUCT_UNAVAILABLE');
        $this->assertQuantity($found['sales'], $quantity, $byRoll);

        $items = $this->carts->items($cartId);
        $isNew = ! in_array($productId, array_column($items, 'product_id'), true);
        ! ($isNew && count($items) >= self::MAX_LINES) || throw new CheckoutError('A cart holds at most '.self::MAX_LINES.' different products.', 'CART_FULL');

        $this->carts->setItem($cartId, $productId, $quantity, $byRoll);
        $this->touch($cart['id'], $cart['market_code']);
    }

    public function removeItem(string $cartId, string $productId): void
    {
        $this->active($cartId);
        $this->carts->removeItem($cartId, $productId);
    }

    public function clear(string $cartId): void
    {
        $this->active($cartId);
        $this->carts->clearItems($cartId);
    }

    public function setCoupon(string $cartId, ?string $code): void
    {
        $this->active($cartId);
        $code = $code === null ? null : strtoupper(trim($code));
        $this->carts->update($cartId, ['coupon_code' => $code === '' ? null : $code]);
    }

    /** Switching market re-prices everything, so lines the new market cannot sell are simply dropped at pricing time. */
    public function setMarket(string $cartId, string $marketCode, ?string $region): void
    {
        $this->active($cartId);
        $this->products->marketCountry(strtoupper($marketCode)) !== null || throw new CheckoutError('That market does not exist.', 'UNKNOWN_MARKET');
        $this->carts->update($cartId, ['market_code' => strtoupper($marketCode), 'region' => $region === null || trim($region) === '' ? null : strtoupper(trim($region))]);
    }

    /** Only the owner (the token holder or the account) may read or change a cart. @return array<string, mixed> */
    public function authorize(string $cartId, ?string $userId, ?string $token): array
    {
        $cart = $this->carts->find($cartId) ?? throw new CheckoutError('That cart does not exist.', 'CART_NOT_FOUND');
        $owns = ($userId !== null && $cart['user_id'] === $userId) || ($token !== null && hash_equals($cart['token_hash'], self::hash($token)));
        $owns || throw new CheckoutError('That cart does not exist.', 'CART_NOT_FOUND'); // same answer: do not confirm that an id exists

        return $cart;
    }

    /** @return array<string, mixed> */
    private function active(string $cartId): array
    {
        $cart = $this->carts->find($cartId);
        ($cart !== null && $cart['status'] === 'active') || throw new CheckoutError('That cart is no longer active.', 'CART_CLOSED');

        return $cart;
    }

    private function assertQuantity(SalesUnit $sales, string $quantity, bool $byRoll): void
    {
        try {
            if ($byRoll) {
                $sales->sellByRoll || throw new CheckoutError('This product is not sold by the roll.', 'NOT_SOLD_BY_ROLL');
                (ctype_digit($quantity) && $quantity !== '0') || throw new CheckoutError('Rolls are counted in whole numbers.', 'INVALID_QUANTITY');
                $sales->assertValidRolls((int) $quantity);
            } else {
                $sales->sellByCut || $sales->unit === 'piece' || throw new CheckoutError('This product is only sold by the roll.', 'ROLL_ONLY');
                $sales->assertValidCut($quantity);
            }
        } catch (InvalidSalesUnit $e) {
            throw new CheckoutError($e->getMessage(), $e->errorCode());
        }
    }

    private function touch(string $cartId, string $market): void
    {
        $this->carts->update($cartId, ['expires_at' => $this->expiry()->format('Y-m-d H:i:sP')]);
    }

    private function expiry(): DateTimeImmutable
    {
        return new DateTimeImmutable('+'.(int) config('checkout.cart_ttl_days', 30).' days');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
