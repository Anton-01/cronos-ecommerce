<?php

declare(strict_types=1);

namespace Cronos\Checkout\Interfaces\GraphQL;

final class Present
{
    /** @param array<string, mixed> $l @return array<string, mixed> */
    public static function line(array $l): array
    {
        return [
            'productId' => $l['product_id'], 'sku' => $l['sku'], 'name' => $l['name'], 'byRoll' => $l['by_roll'], 'quantity' => $l['quantity'],
            'unitPrice' => $l['unit_price'], 'lineTotal' => $l['line_total'], 'discount' => $l['discount'], 'tax' => $l['tax'], 'total' => $l['total'],
        ];
    }

    /** @param array<string, mixed> $p priced basket or checkout row @return array<string, mixed> */
    public static function totals(array $p): array
    {
        return [
            'currency' => $p['currency'], 'taxIncluded' => $p['tax_included'], 'subtotal' => $p['subtotal'], 'itemsDiscount' => $p['items_discount'],
            'shipping' => $p['shipping'], 'shippingDiscount' => $p['shipping_discount'], 'tax' => $p['tax'], 'total' => $p['total'],
            'applied' => array_map(static fn (array $a) => ['kind' => $a['kind'], 'id' => $a['id'], 'name' => $a['name'], 'amount' => ['amount' => $a['amount'], 'currency' => $p['currency']]], $p['applied_discounts'] ?? $p['applied'] ?? []),
        ];
    }
}
