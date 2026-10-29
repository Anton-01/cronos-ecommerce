<?php

declare(strict_types=1);

namespace Cronos\Promotions\Application;

use Cronos\Pricing\Application\PriceQuoter;
use Cronos\Pricing\Domain\Exceptions\InvalidPricing;
use Cronos\Pricing\Domain\ValueObjects\Money;
use Cronos\Promotions\Domain\Exceptions\InvalidCoupon;
use Cronos\Promotions\Domain\Ports\CouponRepository;
use Cronos\Promotions\Domain\Ports\PromotionRepository;
use Cronos\Promotions\Domain\Services\CouponPolicy;
use Cronos\Promotions\Domain\Services\DiscountEngine;
use Cronos\Promotions\Domain\ValueObjects\Cart;
use Cronos\Promotions\Domain\ValueObjects\CartLine;
use Cronos\Promotions\Domain\ValueObjects\DiscountResult;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Prices a basket for the shopper and applies promotions and an optional coupon. The cart (Phase 6) will call this
 * with its own lines; until then the storefront uses it to preview a coupon before checkout.
 */
final class DiscountQuoter
{
    public function __construct(
        private readonly PriceQuoter $prices,
        private readonly CouponRepository $coupons,
        private readonly PromotionRepository $promotions,
        private readonly CouponPolicy $policy,
        private readonly DiscountEngine $engine,
    ) {}

    /**
     * @param  list<array{product_id: string, quantity: string, by_roll?: bool}>  $items
     * @return array{cart: Cart, result: DiscountResult, couponId: ?string}
     */
    public function quote(string $market, array $items, ?string $couponCode, ?string $userId, ?string $shipping = null, bool $isFirstOrder = false, ?string $region = null, ?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable;
        $ids = array_values(array_unique(array_column($items, 'product_id')));
        $quotes = $this->prices->quote($ids, $market, $region, $at);
        $categories = $this->categoriesOf($ids);

        $lines = [];
        $currency = null;
        $decimals = 2;
        foreach ($items as $i => $item) {
            $q = $quotes[$item['product_id']] ?? throw new InvalidPricing('A product in the cart has no price in this market.', 'NOT_PRICED');
            $unit = ! empty($item['by_roll']) ? ($q['roll_price'] ?? throw new InvalidPricing('This product is not sold by the roll.', 'NO_ROLL_PRICE')) : $q['price'];
            $currency = $unit->currency;
            $decimals = $unit->decimals;
            $lines[] = new CartLine((string) $i, $item['product_id'], $categories[$item['product_id']] ?? [], $item['quantity'], $unit->times($item['quantity']), $q['on_sale']);
        }
        $currency !== null || throw new InvalidPricing('The cart is empty.', 'EMPTY_CART');

        $cart = new Cart(strtoupper($market), $currency, $decimals, $lines, new Money($shipping ?? '0', $currency, $decimals), $userId, $isFirstOrder);

        $rule = null;
        if ($couponCode !== null && trim($couponCode) !== '') {
            $row = $this->coupons->findByCode(strtoupper(trim($couponCode))) ?? throw new InvalidCoupon(InvalidCoupon::NOT_FOUND, 'That coupon code does not exist.');
            $rule = RuleFactory::coupon($row);
            $this->policy->assertUsable($rule, $cart, $at, $this->coupons->timesUsedBy($rule->id, $userId));
        }

        $promotions = array_map(RuleFactory::promotion(...), array_filter($this->promotions->all(), static fn (array $p) => $p['is_active']));
        $result = $this->engine->evaluate($cart, array_values($promotions), $rule, $at);

        $usedCoupon = $rule !== null && in_array($rule->id, array_column(array_filter($result->applied, fn ($a) => $a['kind'] === 'coupon'), 'id'), true);

        return ['cart' => $cart, 'result' => $result, 'couponId' => $usedCoupon ? $rule->id : null];
    }

    /**
     * Product id => its categories and all their ancestors (a coupon for "Ribbons" covers a product in "Ribbons > Satin").
     * A variation takes its parent's categories.
     *
     * @param  list<string>  $productIds
     * @return array<string, list<string>>
     */
    private function categoriesOf(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = DB::table('products as p')
            ->join('product_categories as pc', 'pc.product_id', '=', DB::raw('COALESCE(p.parent_id, p.id)'))
            ->join('categories as c', 'c.id', '=', 'pc.category_id')
            ->whereIn('p.id', $productIds)->get(['p.id as product_id', DB::raw('c.path::text as path')]);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->product_id] = array_values(array_unique([...($out[$r->product_id] ?? []), ...explode('.', $r->path)]));
        }

        return $out;
    }
}
