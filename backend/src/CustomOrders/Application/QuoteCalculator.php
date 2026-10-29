<?php

declare(strict_types=1);

namespace Cronos\CustomOrders\Application;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Cronos\CustomOrders\Domain\Exceptions\CustomOrderError;
use Cronos\Pricing\Domain\ValueObjects\Money;

/** Quote arithmetic with the same rule as the shop: tax sits inside the price in tax-inclusive markets and on top elsewhere. */
final class QuoteCalculator
{
    /**
     * @param  list<array{description: string, unit: string, quantity: string, unit_price: string, tax_rate: string, product_id?: ?string}>  $lines
     * @return array{lines: list<array<string, mixed>>, subtotal: string, tax_total: string, shipping_total: string, grand_total: string}
     */
    public function calculate(array $lines, string $shipping, bool $taxIncluded, string $currency, int $decimals): array
    {
        $lines !== [] || throw new CustomOrderError('A quote needs at least one line.', 'EMPTY_QUOTE');
        count($lines) <= 100 || throw new CustomOrderError('A quote holds at most 100 lines.', 'TOO_MANY_LINES');
        (is_numeric($shipping) && (float) $shipping >= 0) || throw new CustomOrderError('Shipping must be zero or more.', 'INVALID_SHIPPING');

        $subtotal = Money::zero($currency, $decimals);
        $tax = Money::zero($currency, $decimals);
        $out = [];
        foreach ($lines as $l) {
            trim($l['description']) !== '' || throw new CustomOrderError('Every line needs a description.', 'INVALID_LINE');
            in_array($l['unit'], ['piece', 'meter', 'roll', 'service'], true) || throw new CustomOrderError('Unknown unit.', 'INVALID_LINE');
            (is_numeric($l['quantity']) && (float) $l['quantity'] > 0 && is_numeric($l['unit_price']) && (float) $l['unit_price'] >= 0) || throw new CustomOrderError('Quantity must be above zero and the price zero or more.', 'INVALID_LINE');
            (is_numeric($l['tax_rate']) && (float) $l['tax_rate'] >= 0 && (float) $l['tax_rate'] <= 100) || throw new CustomOrderError('The tax rate is a percentage between 0 and 100.', 'INVALID_LINE');

            $amount = (new Money($l['unit_price'], $currency, $decimals))->times($l['quantity']);
            $rate = BigDecimal::of($l['tax_rate']);
            $lineTax = $taxIncluded
                ? new Money($amount->amount->multipliedBy($rate)->dividedBy($rate->plus(100), $decimals + 4, RoundingMode::HalfUp), $currency, $decimals)
                : $amount->percent($l['tax_rate']);
            $subtotal = $subtotal->plus($amount);
            $tax = $tax->plus($lineTax);
            $out[] = ['description' => trim($l['description']), 'unit' => $l['unit'], 'quantity' => $l['quantity'], 'unit_price' => (new Money($l['unit_price'], $currency, $decimals))->value(),
                'tax_rate' => $l['tax_rate'], 'tax_amount' => $lineTax->value(), 'total' => ($taxIncluded ? $amount : $amount->plus($lineTax))->value(), 'product_id' => $l['product_id'] ?? null];
        }
        $ship = new Money($shipping, $currency, $decimals);
        $grand = ($taxIncluded ? $subtotal : $subtotal->plus($tax))->plus($ship);

        return ['lines' => $out, 'subtotal' => $subtotal->value(), 'tax_total' => $tax->value(), 'shipping_total' => $ship->value(), 'grand_total' => $grand->value()];
    }
}
