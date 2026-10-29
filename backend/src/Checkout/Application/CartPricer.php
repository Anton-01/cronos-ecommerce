<?php

declare(strict_types=1);

namespace Cronos\Checkout\Application;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Cronos\Checkout\Domain\Exceptions\CheckoutError;
use Cronos\Checkout\Domain\Ports\SellableProducts;
use Cronos\Checkout\Domain\Ports\ShippingRater;
use Cronos\Pricing\Application\PriceQuoter;
use Cronos\Pricing\Domain\ValueObjects\Money;
use Cronos\Promotions\Application\DiscountQuoter;

/**
 * Turns basket lines into what the shopper owes: unit prices (by cut or by roll), promotions and coupon, shipping, and tax.
 *
 * Tax is computed on what is left after discounts, using each product's tax rate (the sum of its tax lines; compound taxes are not modelled yet): inside the price in markets that
 * show tax-inclusive prices (MX), on top of it in the others (US). Shipping is not taxed yet (documented limitation).
 */
final class CartPricer
{
    public function __construct(
        private readonly PriceQuoter $prices,
        private readonly DiscountQuoter $discounts,
        private readonly SellableProducts $products,
        private readonly ShippingRater $shipping,
    ) {}

    /**
     * @param  list<array{product_id: string, quantity: string, by_roll: bool}>  $items
     * @return array<string, mixed>
     */
    public function price(string $market, ?string $region, array $items, ?string $couponCode, ?string $userId, bool $isFirstOrder = false): array
    {
        $items !== [] || throw new CheckoutError('The cart is empty.', 'EMPTY_CART');
        $ids = array_column($items, 'product_id');
        $known = $this->products->find($ids);
        foreach ($ids as $id) {
            isset($known[$id]) || throw new CheckoutError('A product in your cart is no longer available.', 'PRODUCT_UNAVAILABLE');
        }

        $quoteItems = array_map(static fn (array $i) => ['product_id' => $i['product_id'], 'quantity' => $i['quantity'], 'by_roll' => $i['by_roll']], $items);

        // Two passes: shipping depends on the discounted items, and a free-shipping coupon needs a shipping amount to cancel.
        $first = $this->discounts->quote($market, $quoteItems, $couponCode, $userId, '0', $isFirstOrder, $region);
        $itemsAfter = $first['result']->subtotal->minus($first['result']->itemsDiscount());
        $shipping = $this->shipping->rate($market, $itemsAfter->value());
        ['cart' => $cart, 'result' => $result, 'couponId' => $couponId] = $this->discounts->quote($market, $quoteItems, $couponCode, $userId, $shipping, $isFirstOrder, $region);

        $quotes = $this->prices->quote(array_values(array_unique($ids)), $market, $region);
        $currency = $cart->currency;
        $decimals = $cart->decimals;
        $included = false;
        $lines = [];
        $tax = Money::zero($currency, $decimals);
        $payable = Money::zero($currency, $decimals);

        foreach ($cart->lines as $i => $line) {
            $item = $items[$i];
            $quote = $quotes[$item['product_id']] ?? throw new CheckoutError('A product in your cart has no price in this market.', 'NOT_PRICED');
            $included = $quote['tax_included'];
            $discount = $result->lineDiscounts[$line->id];
            $after = $line->total->minus($discount);

            // The exact rate (16 %), not tax/net of one rounded unit price, so a 2-cent rounding of the unit never leaks into the total.
            $ratio = array_reduce($quote['tax_lines'], static fn (BigDecimal $c, array $l): BigDecimal => $c->plus(BigDecimal::of($l['rate'])), BigDecimal::zero())->dividedBy(100, 10, RoundingMode::HalfUp);
            $lineTax = $included
                ? new Money($after->amount->multipliedBy($ratio)->dividedBy(BigDecimal::one()->plus($ratio), $decimals + 4, RoundingMode::HalfUp), $currency, $decimals)
                : new Money($after->amount->multipliedBy($ratio), $currency, $decimals);

            $tax = $tax->plus($lineTax);
            $due = $included ? $after : $after->plus($lineTax);
            $payable = $payable->plus($due);

            $meta = $known[$item['product_id']];
            $lines[] = [
                'product_id' => $item['product_id'], 'sku' => $meta['sku'], 'name' => $meta['name'], 'by_roll' => $item['by_roll'], 'quantity' => $item['quantity'],
                'unit_price' => $item['by_roll'] ? $quote['roll_price']->value() : $quote['price']->value(),
                'line_total' => $line->total->value(), 'discount' => $discount->value(), 'tax' => $lineTax->value(), 'total' => $due->value(),
            ];
        }

        $shippingMoney = new Money($shipping, $currency, $decimals);
        $shippingDue = $shippingMoney->minus($result->shippingDiscount);
        $total = $payable->plus($shippingDue);

        return [
            'market' => $cart->market, 'region' => $region, 'currency' => $currency, 'decimals' => $decimals, 'tax_included' => $included,
            'lines' => $lines, 'subtotal' => $result->subtotal->value(), 'items_discount' => $result->itemsDiscount()->value(),
            'shipping' => $shippingMoney->value(), 'shipping_discount' => $result->shippingDiscount->value(), 'tax' => $tax->value(), 'total' => $total->value(),
            'applied' => array_map(static fn (array $a) => ['kind' => $a['kind'], 'id' => $a['id'], 'name' => $a['name'], 'amount' => $a['amount']->value()], $result->applied),
            'coupon_id' => $couponId, 'coupon_code' => $couponId === null ? null : strtoupper(trim((string) $couponCode)),
        ];
    }
}
