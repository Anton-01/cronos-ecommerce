<?php

declare(strict_types=1);

namespace Cronos\Orders\Application;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Cronos\Orders\Domain\Ports\OrderRepository;
use Cronos\Shared\Domain\ClientContext;
use Illuminate\Support\Str;

/** Creates the order behind an accepted quote: type "custom", waiting for payment (unpaid), with the quote's lines and totals frozen in. */
final class CustomOrderPlacer
{
    public function __construct(private readonly OrderRepository $orders) {}

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $quote  with lines
     * @param  array<string, mixed>  $shipping
     * @param  array<string, mixed>  $billing
     * @return array<string, mixed> the order
     */
    public function place(array $request, array $quote, array $shipping, array $billing, ?ClientContext $client): array
    {
        $id = (string) Str::ulid();
        $order = [
            'id' => $id, 'order_number' => $this->orders->nextNumber(), 'order_type' => 'custom', 'user_id' => $request['user_id'], 'email' => $request['email'],
            'market_code' => $request['market_code'], 'currency' => $quote['currency'], 'tax_included' => $quote['tax_included'], 'status' => 'pending', 'payment_status' => 'unpaid',
            'subtotal' => $quote['subtotal'], 'discount_total' => '0.00', 'shipping_total' => $quote['shipping_total'], 'tax_total' => $quote['tax_total'], 'grand_total' => $quote['grand_total'],
            'source_id' => $quote['id'], 'customer_note' => $request['title'], 'ip_address' => $client?->ip, 'country_code' => $client?->country, 'timezone' => $client?->timezone,
        ];
        $items = array_map(static fn (array $l) => [
            'product_id' => $l['product_id'], 'product_name' => $l['description'], 'sku' => null, 'unit' => $l['unit'] === 'service' ? 'piece' : $l['unit'], 'quantity' => $l['quantity'],
            'unit_price' => $l['unit_price'], 'discount_amount' => '0.00', 'tax_rate' => $l['tax_rate'], 'tax_amount' => $l['tax_amount'],
            'subtotal' => (string) BigDecimal::of($l['unit_price'])->multipliedBy($l['quantity'])->toScale(2, RoundingMode::HalfUp), 'total' => $l['total'],
            'options' => ['custom_request' => $request['number'], 'service' => $l['unit'] === 'service'],
        ], $quote['lines']);

        $this->orders->insert($order, $items, ['shipping' => $shipping, 'billing' => $billing], []);
        $this->orders->addHistory($id, ['status' => 'pending', 'payment_status' => 'unpaid', 'note' => "Custom order {$request['number']} accepted; waiting for payment", 'notify_customer' => false, 'created_by' => $request['user_id'], 'ip_address' => $client?->ip, 'country_code' => $client?->country, 'timezone' => $client?->timezone]);

        return $this->orders->find($id);
    }

    /** @return array<string, mixed> */
    public function findForQuote(string $quoteId): array
    {
        return $this->orders->findBySource($quoteId) ?? throw new \LogicException("Quote {$quoteId} is accepted but has no order.");
    }
}
