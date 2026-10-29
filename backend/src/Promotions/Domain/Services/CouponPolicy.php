<?php

declare(strict_types=1);

namespace Cronos\Promotions\Domain\Services;

use Cronos\Promotions\Domain\Exceptions\InvalidCoupon;
use Cronos\Promotions\Domain\ValueObjects\Cart;
use Cronos\Promotions\Domain\ValueObjects\CouponRule;
use DateTimeImmutable;

/** Can this shopper use this coupon on this cart right now? Throws the first reason it cannot. */
final class CouponPolicy
{
    public function assertUsable(CouponRule $coupon, Cart $cart, DateTimeImmutable $at, int $timesUsedByThisUser): void
    {
        $coupon->isActive || throw new InvalidCoupon(InvalidCoupon::INACTIVE, 'This coupon is not active.');
        $coupon->restrictions->appliesToMarket($cart->market) || throw new InvalidCoupon(InvalidCoupon::MARKET, 'This coupon is not valid in your country.');
        ($coupon->startsAt === null || $coupon->startsAt <= $at) || throw new InvalidCoupon(InvalidCoupon::NOT_STARTED, 'This coupon is not valid yet.');
        ($coupon->expiresAt === null || $at < $coupon->expiresAt) || throw new InvalidCoupon(InvalidCoupon::EXPIRED, 'This coupon has expired.');
        ($coupon->maxUses === null || $coupon->usesCount < $coupon->maxUses) || throw new InvalidCoupon(InvalidCoupon::EXHAUSTED, 'This coupon has been fully redeemed.');

        if ($coupon->maxUsesPerUser !== null) {
            $cart->userId !== null || throw new InvalidCoupon(InvalidCoupon::USER_LIMIT, 'Sign in to use this coupon.');
            $timesUsedByThisUser < $coupon->maxUsesPerUser || throw new InvalidCoupon(InvalidCoupon::USER_LIMIT, 'You have already used this coupon the allowed number of times.');
        }

        ! $coupon->restrictions->firstOrderOnly || $cart->isFirstOrder || throw new InvalidCoupon(InvalidCoupon::FIRST_ORDER, 'This coupon is only valid on a first order.');
        $coupon->type !== 'fixed' || $coupon->currency === $cart->currency || throw new InvalidCoupon(InvalidCoupon::CURRENCY, "This coupon is only valid for orders in {$coupon->currency}.");

        $subtotal = $cart->subtotal()->amount;
        foreach ([$coupon->minSpend, $coupon->restrictions->minSubtotal] as $min) {
            $min === null || $subtotal->isGreaterThanOrEqualTo($min) || throw new InvalidCoupon(InvalidCoupon::MIN_SPEND, "Spend at least {$min} {$cart->currency} to use this coupon.");
        }

        $applicable = $coupon->type === 'free_shipping'
            ? ! $cart->shipping->isZero()
            : array_filter($cart->lines, static fn ($l) => $coupon->restrictions->matches($l)) !== [];
        $applicable || throw new InvalidCoupon(InvalidCoupon::NOT_APPLICABLE, 'This coupon does not apply to the items in your cart.');
    }
}
