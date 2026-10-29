<?php

declare(strict_types=1);

namespace Cronos\Promotions\Application;

use Cronos\Promotions\Domain\Exceptions\InvalidCoupon;
use Cronos\Promotions\Domain\Ports\CouponRepository;

/** Called when an order is placed: burns one use of the coupon, atomically and once per order. */
final class CouponRedeemer
{
    public function __construct(private readonly CouponRepository $coupons) {}

    public function redeem(string $couponId, ?string $userId, ?string $orderId, string $discountAmount, string $currency): void
    {
        $this->coupons->redeem($couponId, $userId, $orderId, $discountAmount, $currency)
            || throw new InvalidCoupon(InvalidCoupon::EXHAUSTED, 'This coupon has just been fully redeemed.');
    }
}
