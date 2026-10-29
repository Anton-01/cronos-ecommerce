<?php

namespace Tests\Feature\Promotions;

use Cronos\Pricing\Domain\Exceptions\InvalidPricing;
use Cronos\Promotions\Application\CouponManager;
use Cronos\Promotions\Application\CouponRedeemer;
use Cronos\Promotions\Application\DiscountQuoter;
use Cronos\Promotions\Application\PromotionManager;
use Cronos\Promotions\Domain\Exceptions\InvalidCoupon;
use Cronos\Promotions\Domain\Exceptions\InvalidPromotion;
use Cronos\Promotions\Domain\Ports\CouponRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Pricing\PricingTestCase;

final class PromotionsServicesTest extends PricingTestCase
{
    private function coupon(array $o = []): string
    {
        return app(CouponManager::class)->create($o + ['code' => 'bienvenida10', 'name' => 'Bienvenida', 'type' => 'percentage', 'value' => '10']);
    }

    private function quote(array $items, ?string $code = null, string $market = 'MX', ?string $user = 'user-1', ?string $shipping = null): array
    {
        return app(DiscountQuoter::class)->quote($market, $items, $code, $user, $shipping);
    }

    public function test_coupon_codes_are_normalised_unique_case_insensitively_and_validated(): void
    {
        $id = $this->coupon();
        $this->assertSame('BIENVENIDA10', app(CouponRepository::class)->find($id)['code']);
        $this->assertNotNull(app(CouponRepository::class)->findByCode('BIENVENIDA10'));

        foreach ([
            fn () => $this->coupon(),
            fn () => $this->coupon(['code' => 'x', 'name' => 'short']),
            fn () => $this->coupon(['code' => 'OVER', 'value' => '150']),
            fn () => $this->coupon(['code' => 'FIXED', 'type' => 'fixed', 'value' => '50']),
            fn () => $this->coupon(['code' => 'WIN', 'starts_at' => '2026-02-01', 'expires_at' => '2026-01-01']),
        ] as $bad) {
            try {
                $bad();
                $this->fail('expected a domain error');
            } catch (InvalidPromotion) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_coupon_discounts_a_real_cart_priced_by_market_and_ribbons_by_the_roll(): void
    {
        $bow = $this->published($this->piece(price: '100.00'));
        $ribbon = $this->published($this->ribbon());
        $this->coupon();

        $r = $this->quote([['product_id' => $bow, 'quantity' => '2'], ['product_id' => $ribbon, 'quantity' => '1', 'by_roll' => true]], 'bienvenida10', shipping: '99');

        $this->assertSame('MXN', $r['cart']->currency);
        $this->assertSame('600.00', $r['result']->subtotal->value(), '2 x 100 + one 400 roll');
        $this->assertSame('60.00', $r['result']->totalDiscount()->value());
        $this->assertNotNull($r['couponId']);
    }

    public function test_the_same_percentage_coupon_works_in_usd_but_a_fixed_mxn_coupon_does_not(): void
    {
        $bow = $this->published($this->piece(price: '100.00'));
        $this->coupon();
        $this->coupon(['code' => 'MENOS50', 'type' => 'fixed', 'value' => '50', 'currency' => 'MXN']);

        $us = $this->quote([['product_id' => $bow, 'quantity' => '4']], 'BIENVENIDA10', 'US');
        $this->assertSame(['USD', '22.00', '2.20'], [$us['cart']->currency, $us['result']->subtotal->value(), $us['result']->totalDiscount()->value()]);

        try {
            $this->quote([['product_id' => $bow, 'quantity' => '1']], 'MENOS50', 'US');
            $this->fail('expected the currency mismatch');
        } catch (InvalidCoupon $e) {
            $this->assertSame(InvalidCoupon::CURRENCY, $e->errorCode());
        }
    }

    public function test_category_restrictions_include_sub_categories(): void
    {
        $ribbons = $this->category('Listones');
        $satin = $this->category('Satén', $ribbons);
        $other = $this->category('Moños');
        $inSatin = $this->published($this->ribbon(extra: ['category_ids' => [$satin]]));
        $bow = $this->published($this->piece(price: '100.00', extra: ['category_ids' => [$other]]));
        $this->coupon(['restrictions' => ['category_ids' => [$ribbons]]]);

        $r = $this->quote([['product_id' => $inSatin, 'quantity' => '10'], ['product_id' => $bow, 'quantity' => '1']], 'BIENVENIDA10');

        $this->assertSame('18.00', $r['result']->totalDiscount()->value(), '10 % of the 180 of ribbon only; the bow in another category is excluded');
    }

    public function test_unknown_expired_and_exhausted_coupons_are_refused_with_a_code(): void
    {
        $bow = $this->published($this->piece());
        $this->coupon(['code' => 'OLD', 'expires_at' => '2020-01-01']);
        $this->coupon(['code' => 'GONE', 'max_uses' => 1]);
        app(CouponRedeemer::class)->redeem(app(CouponRepository::class)->findByCode('GONE')['id'], 'u1', 'o1', '5', 'MXN');

        foreach (['NOPE' => InvalidCoupon::NOT_FOUND, 'OLD' => InvalidCoupon::EXPIRED, 'GONE' => InvalidCoupon::EXHAUSTED] as $code => $reason) {
            try {
                $this->quote([['product_id' => $bow, 'quantity' => '1']], $code);
                $this->fail("expected {$reason}");
            } catch (InvalidCoupon $e) {
                $this->assertSame($reason, $e->errorCode());
            }
        }
    }

    public function test_redeeming_is_idempotent_per_order_and_never_exceeds_the_limits(): void
    {
        $repo = app(CouponRepository::class);
        $id = $this->coupon(['code' => 'LIMITED', 'max_uses' => 2, 'max_uses_per_user' => 1]);
        $redeemer = app(CouponRedeemer::class);

        $redeemer->redeem($id, 'u1', 'order-1', '10', 'MXN');
        $redeemer->redeem($id, 'u1', 'order-1', '10', 'MXN'); // a retry of the same order
        $this->assertSame(1, $repo->find($id)['uses_count'], 'a retry does not burn a second use');

        try {
            $redeemer->redeem($id, 'u1', 'order-2', '10', 'MXN');
            $this->fail('u1 already used it once');
        } catch (InvalidCoupon) {
            $this->addToAssertionCount(1);
        }

        $redeemer->redeem($id, 'u2', 'order-3', '10', 'MXN');
        $this->expectException(InvalidCoupon::class);
        $redeemer->redeem($id, 'u3', 'order-4', '10', 'MXN'); // max_uses reached
    }

    public function test_redemptions_are_an_immutable_ledger(): void
    {
        $id = $this->coupon();
        app(CouponRedeemer::class)->redeem($id, 'u1', 'o1', '10', 'MXN');

        $this->expectException(QueryException::class);
        DB::table('coupon_usages')->delete();
    }

    public function test_automatic_promotions_apply_without_a_code_and_combine_with_a_stackable_coupon(): void
    {
        $bow = $this->published($this->piece(price: '100.00'));
        app(PromotionManager::class)->create(['name' => '2x1 en moños', 'priority' => 1, 'action' => ['type' => 'buy_x_get_y', 'buy' => 1, 'get' => 1, 'percent' => '100']]);
        app(PromotionManager::class)->create(['name' => 'Envío gratis +500', 'priority' => 2, 'conditions' => ['min_subtotal' => '300'], 'action' => ['type' => 'free_shipping']]);
        $this->coupon(['is_stackable' => true]);

        $r = $this->quote([['product_id' => $bow, 'quantity' => '4']], 'BIENVENIDA10', shipping: '99');

        // 4 bows -> 2 free (200), coupon 10 % of the remaining 200 (20), shipping 99 free.
        $this->assertSame(['220.00', '99.00', '319.00'], [$r['result']->itemsDiscount()->value(), $r['result']->shippingDiscount->value(), $r['result']->totalDiscount()->value()]);
        $this->assertSame(['promotion', 'promotion', 'coupon'], array_column($r['result']->applied, 'kind'));

        $none = $this->quote([['product_id' => $bow, 'quantity' => '1']], null, shipping: '99');
        $this->assertSame('0.00', $none['result']->totalDiscount()->value(), 'one bow is below the 2x1 group and the 300 shipping threshold');
    }

    public function test_an_unpriced_or_empty_cart_is_refused(): void
    {
        $noPrice = $this->products()->create(['type' => 'simple', 'sku' => 'NP', 'translations' => ['es-MX' => ['name' => 'Sin precio']]]);

        $this->expectException(InvalidPricing::class);
        $this->quote([['product_id' => $noPrice, 'quantity' => '1']]);
    }
}
