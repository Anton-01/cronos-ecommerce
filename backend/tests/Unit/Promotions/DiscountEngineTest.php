<?php

declare(strict_types=1);

namespace Tests\Unit\Promotions;

use Cronos\Pricing\Domain\ValueObjects\Money;
use Cronos\Promotions\Domain\Exceptions\InvalidCoupon;
use Cronos\Promotions\Domain\Exceptions\InvalidPromotion;
use Cronos\Promotions\Domain\Services\CouponPolicy;
use Cronos\Promotions\Domain\Services\DiscountEngine;
use Cronos\Promotions\Domain\ValueObjects\Cart;
use Cronos\Promotions\Domain\ValueObjects\CartLine;
use Cronos\Promotions\Domain\ValueObjects\CouponRule;
use Cronos\Promotions\Domain\ValueObjects\PromotionRule;
use Cronos\Promotions\Domain\ValueObjects\Restrictions;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiscountEngineTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-06-01 12:00:00');
    }

    private function m(string $v): Money
    {
        return new Money($v, 'MXN');
    }

    /** @param list<CartLine> $lines */
    private function cart(array $lines, string $shipping = '99', ?string $user = 'u1', bool $first = false): Cart
    {
        return new Cart('MX', 'MXN', 2, $lines, $this->m($shipping), $user, $first);
    }

    private function line(string $id, string $total, string $qty = '1', array $cats = [], bool $sale = false): CartLine
    {
        return new CartLine($id, 'p-'.$id, $cats, $qty, $this->m($total), $sale);
    }

    private function coupon(array $o = []): CouponRule
    {
        return new CouponRule(
            $o['id'] ?? 'c1', $o['code'] ?? 'SAVE', 'Coupon', $o['type'] ?? 'percentage', $o['value'] ?? '10', $o['currency'] ?? null, $o['min'] ?? null, $o['max'] ?? null,
            $o['maxUses'] ?? null, $o['perUser'] ?? null, $o['uses'] ?? 0, $o['stackable'] ?? false, $o['active'] ?? true, $o['starts'] ?? null, $o['expires'] ?? null, $o['restrictions'] ?? new Restrictions,
        );
    }

    private function promo(array $action, array $o = []): PromotionRule
    {
        return new PromotionRule($o['id'] ?? 'pr1', 'Promo', $o['priority'] ?? 0, $o['stackable'] ?? true, true, $o['starts'] ?? null, $o['ends'] ?? null, $o['conditions'] ?? new Restrictions, PromotionRule::validateAction($action));
    }

    public function test_a_percentage_coupon_discounts_the_items_and_splits_across_lines_to_the_cent(): void
    {
        $r = (new DiscountEngine)->evaluate($this->cart([$this->line('a', '100.00'), $this->line('b', '33.33'), $this->line('c', '66.67')]), [], $this->coupon(['value' => '15']), $this->now);

        $this->assertSame('30.00', $r->itemsDiscount()->value());
        $this->assertSame('30.00', $r->totalDiscount()->value());
        $this->assertSame(['15.00', '5.00', '10.00'], array_values(array_map(fn ($d) => $d->value(), $r->lineDiscounts)));
    }

    public function test_a_fixed_coupon_never_exceeds_the_eligible_amount_and_respects_the_max_discount(): void
    {
        $engine = new DiscountEngine;
        $cart = $this->cart([$this->line('a', '80.00')]);

        $this->assertSame('50.00', $engine->evaluate($cart, [], $this->coupon(['type' => 'fixed', 'value' => '50', 'currency' => 'MXN']), $this->now)->totalDiscount()->value());
        $this->assertSame('80.00', $engine->evaluate($cart, [], $this->coupon(['type' => 'fixed', 'value' => '500', 'currency' => 'MXN']), $this->now)->totalDiscount()->value(), 'cannot discount more than the items');
        $this->assertSame('20.00', $engine->evaluate($cart, [], $this->coupon(['value' => '50', 'max' => '20']), $this->now)->totalDiscount()->value(), '50 % capped at 20');
    }

    public function test_free_shipping_discounts_only_the_shipping(): void
    {
        $r = (new DiscountEngine)->evaluate($this->cart([$this->line('a', '200')], '99'), [], $this->coupon(['type' => 'free_shipping', 'value' => '0']), $this->now);

        $this->assertSame(['0.00', '99.00'], [$r->itemsDiscount()->value(), $r->shippingDiscount->value()]);
    }

    public function test_a_coupon_restricted_to_a_category_only_discounts_those_lines_and_can_skip_sale_items(): void
    {
        $cart = $this->cart([$this->line('a', '100', '1', ['ribbons']), $this->line('b', '100', '1', ['bows']), $this->line('c', '100', '1', ['ribbons'], true)]);
        $coupon = $this->coupon(['value' => '10', 'restrictions' => new Restrictions(categoryIds: ['ribbons'], excludeSaleItems: true)]);

        $r = (new DiscountEngine)->evaluate($cart, [], $coupon, $this->now);

        $this->assertSame('10.00', $r->totalDiscount()->value());
        $this->assertSame('10.00', $r->lineDiscounts['a']->value());
        $this->assertSame('0.00', $r->lineDiscounts['c']->value());
    }

    public function test_promotions_apply_by_priority_and_stack_with_a_stackable_coupon_on_what_is_left(): void
    {
        $cart = $this->cart([$this->line('a', '100.00')]);
        $promos = [$this->promo(['type' => 'percentage_off', 'value' => '10'], ['id' => 'p10', 'priority' => 1])];
        $r = (new DiscountEngine)->evaluate($cart, $promos, $this->coupon(['value' => '10', 'stackable' => true]), $this->now);

        $this->assertSame('19.00', $r->totalDiscount()->value(), '10 % off 100 = 10, then 10 % of the remaining 90 = 9');
        $this->assertSame(['promotion', 'coupon'], array_column($r->applied, 'kind'));
    }

    public function test_a_non_stackable_coupon_competes_with_promotions_and_the_better_deal_wins(): void
    {
        $cart = $this->cart([$this->line('a', '100.00')]);
        $promo = $this->promo(['type' => 'percentage_off', 'value' => '20']);
        $engine = new DiscountEngine;

        $couponWins = $engine->evaluate($cart, [$promo], $this->coupon(['value' => '30']), $this->now);
        $promoWins = $engine->evaluate($cart, [$promo], $this->coupon(['value' => '10']), $this->now);

        $this->assertSame(['30.00', ['coupon']], [$couponWins->totalDiscount()->value(), array_column($couponWins->applied, 'kind')]);
        $this->assertSame(['20.00', ['promotion']], [$promoWins->totalDiscount()->value(), array_column($promoWins->applied, 'kind')]);
    }

    public function test_a_non_stackable_promotion_stands_alone(): void
    {
        $cart = $this->cart([$this->line('a', '100.00')]);
        $promos = [
            $this->promo(['type' => 'percentage_off', 'value' => '10'], ['id' => 'x', 'priority' => 0, 'stackable' => false]),
            $this->promo(['type' => 'percentage_off', 'value' => '50'], ['id' => 'y', 'priority' => 1]),
        ];

        $r = (new DiscountEngine)->evaluate($cart, $promos, null, $this->now);

        $this->assertSame(['10.00', ['x']], [$r->totalDiscount()->value(), array_column($r->applied, 'id')]);
    }

    public function test_promotions_respect_their_window_market_and_minimum_subtotal(): void
    {
        $cart = $this->cart([$this->line('a', '100.00')]);
        $engine = new DiscountEngine;
        $pct = ['type' => 'percentage_off', 'value' => '10'];

        $expired = $this->promo($pct, ['ends' => new DateTimeImmutable('2026-05-31')]);
        $future = $this->promo($pct, ['starts' => new DateTimeImmutable('2026-06-02')]);
        $us = $this->promo($pct, ['conditions' => new Restrictions(marketCodes: ['US'])]);
        $big = $this->promo($pct, ['conditions' => new Restrictions(minSubtotal: '150')]);
        $ok = $this->promo($pct, ['conditions' => new Restrictions(marketCodes: ['MX'], minSubtotal: '100')]);

        $this->assertSame('0.00', $engine->evaluate($cart, [$expired, $future, $us, $big], null, $this->now)->totalDiscount()->value());
        $this->assertSame('10.00', $engine->evaluate($cart, [$ok], null, $this->now)->totalDiscount()->value());
    }

    public function test_buy_x_get_y_gives_the_free_units_of_each_complete_group(): void
    {
        // Buy 2 get 1 free: 7 bows at 10 each -> 2 complete groups of 3 -> 2 free bows.
        $cart = $this->cart([$this->line('a', '70.00', '7')]);
        $promo = $this->promo(['type' => 'buy_x_get_y', 'buy' => 2, 'get' => 1, 'percent' => '100']);

        $this->assertSame('20.00', (new DiscountEngine)->evaluate($cart, [$promo], null, $this->now)->totalDiscount()->value());
        $half = $this->promo(['type' => 'buy_x_get_y', 'buy' => 1, 'get' => 1, 'percent' => '50']);
        $this->assertSame('15.00', (new DiscountEngine)->evaluate($cart, [$half], null, $this->now)->totalDiscount()->value(), '3 groups of 2 -> 3 units at 50 %');
    }

    public function test_a_fixed_promotion_in_another_currency_is_ignored(): void
    {
        $promo = $this->promo(['type' => 'fixed_off', 'value' => '20', 'currency' => 'USD']);

        $this->assertSame('0.00', (new DiscountEngine)->evaluate($this->cart([$this->line('a', '100')]), [$promo], null, $this->now)->totalDiscount()->value());
    }

    public function test_invalid_promotion_actions_are_refused(): void
    {
        foreach ([['type' => 'percentage_off', 'value' => '150'], ['type' => 'fixed_off', 'value' => '5'], ['type' => 'buy_x_get_y', 'buy' => 0, 'get' => 1, 'percent' => '100'], ['type' => 'nonsense']] as $bad) {
            try {
                PromotionRule::validateAction($bad);
                $this->fail('expected '.json_encode($bad).' to be refused');
            } catch (InvalidPromotion) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function unusableCoupons(): iterable
    {
        yield 'inactive' => [['active' => false], InvalidCoupon::INACTIVE];
        yield 'not started' => [['starts' => new DateTimeImmutable('2026-06-02')], InvalidCoupon::NOT_STARTED];
        yield 'expired' => [['expires' => new DateTimeImmutable('2026-06-01 12:00:00')], InvalidCoupon::EXPIRED];
        yield 'exhausted' => [['maxUses' => 5, 'uses' => 5], InvalidCoupon::EXHAUSTED];
        yield 'user limit' => [['perUser' => 1], InvalidCoupon::USER_LIMIT];
        yield 'min spend' => [['min' => '500'], InvalidCoupon::MIN_SPEND];
        yield 'wrong currency' => [['type' => 'fixed', 'value' => '5', 'currency' => 'USD'], InvalidCoupon::CURRENCY];
        yield 'other market' => [['restrictions' => new Restrictions(marketCodes: ['US'])], InvalidCoupon::MARKET];
        yield 'first order only' => [['restrictions' => new Restrictions(firstOrderOnly: true)], InvalidCoupon::FIRST_ORDER];
        yield 'nothing eligible' => [['restrictions' => new Restrictions(productIds: ['other'])], InvalidCoupon::NOT_APPLICABLE];
    }

    #[DataProvider('unusableCoupons')]
    public function test_the_policy_names_why_a_coupon_cannot_be_used(array $options, string $code): void
    {
        try {
            (new CouponPolicy)->assertUsable($this->coupon($options), $this->cart([$this->line('a', '100')]), $this->now, 1);
            $this->fail('expected the coupon to be refused');
        } catch (InvalidCoupon $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }

    public function test_guests_cannot_use_a_coupon_limited_per_user_and_a_valid_coupon_passes(): void
    {
        $policy = new CouponPolicy;
        $this->expectNotToPerformAssertions();
        $policy->assertUsable($this->coupon(['perUser' => 2]), $this->cart([$this->line('a', '100')]), $this->now, 1);
        $policy->assertUsable($this->coupon(), $this->cart([$this->line('a', '100')], user: null), $this->now, 0);
    }
}
