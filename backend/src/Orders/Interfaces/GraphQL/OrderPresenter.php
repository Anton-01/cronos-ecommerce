<?php

declare(strict_types=1);

namespace Cronos\Orders\Interfaces\GraphQL;

use Cronos\Orders\Domain\Ports\OrderRepository;
use Cronos\Orders\Domain\Services\OrderStateMachine;
use Cronos\Shipping\Domain\Ports\ShipmentRepository;

/** Turns order rows into the shapes the GraphQL types ask for. The storefront view hides internal notes and fraud data. */
final class OrderPresenter
{
    public function __construct(private readonly OrderRepository $orders, private readonly ShipmentRepository $shipments) {}

    /** @param array<string, mixed> $o @return array<string, mixed> */
    public function customer(array $o): array
    {
        $items = $this->orders->items($o['id']);

        return $this->base($o, $items) + [
            'shippingAddress' => $this->orders->addresses($o['id'])['shipping'] ?? null,
            'timeline' => array_values(array_map(static fn (array $h) => ['status' => $h['status'], 'note' => $h['notify_customer'] ? $h['note'] : null, 'at' => $h['created_at']],
                array_filter($this->orders->histories($o['id']), static fn (array $h) => $h['notify_customer']))),
            'shipments' => $this->shipments($o['id'], $items),
        ];
    }

    /** @param array<string, mixed> $o @return array<string, mixed> */
    public function admin(array $o): array
    {
        $items = $this->orders->items($o['id']);
        $addresses = $this->orders->addresses($o['id']);

        return $this->base($o, $items) + [
            'type' => $o['order_type'], 'email' => $o['email'], 'userId' => $o['user_id'], 'marketCode' => $o['market_code'], 'paymentId' => $o['payment_id'], 'checkoutId' => $o['checkout_id'],
            'fraudScore' => (int) $o['fraud_score'], 'fraudDecision' => $o['fraud_decision'], 'ip' => $o['ip_address'], 'country' => $o['country'], 'timezone' => $o['timezone'],
            'cancelledAt' => $o['cancelled_at'], 'createdAt' => $o['created_at'],
            'shippingAddress' => $addresses['shipping'] ?? null, 'billingAddress' => $addresses['billing'] ?? null,
            'discounts' => $this->orders->discounts($o['id']),
            'history' => array_map(static fn (array $h) => [
                'id' => $h['id'], 'status' => $h['status'], 'paymentStatus' => $h['payment_status'], 'note' => $h['note'], 'notifyCustomer' => $h['notify_customer'],
                'createdBy' => $h['created_by'], 'ip' => $h['ip_address'], 'country' => $h['country'], 'timezone' => $h['timezone'], 'createdAt' => $h['created_at'],
            ], $this->orders->histories($o['id'])),
            'nextStatuses' => array_values(array_diff(OrderStateMachine::next($o['status']), [OrderStateMachine::CANCELLED])),
            'shipments' => $this->shipments($o['id'], $items),
        ];
    }

    /** @param array<string, mixed> $o @param list<array<string, mixed>> $items @return array<string, mixed> */
    private function base(array $o, array $items): array
    {
        return [
            'id' => $o['id'], 'number' => $o['order_number'], 'status' => $o['status'], 'paymentStatus' => $o['payment_status'], 'fulfillmentStatus' => $o['fulfillment_status'],
            'currency' => $o['currency'], 'taxIncluded' => $o['tax_included'], 'subtotal' => $o['subtotal'], 'discount' => $o['discount_total'], 'shipping' => $o['shipping_total'],
            'tax' => $o['tax_total'], 'total' => $o['grand_total'], 'refunded' => $o['refunded_total'], 'couponCode' => $o['coupon_code'], 'placedAt' => $o['placed_at'],
            'items' => array_map(static fn (array $i) => [
                'productId' => $i['product_id'], 'name' => $i['product_name'], 'sku' => $i['sku'], 'unit' => $i['unit'], 'quantity' => $i['quantity'], 'unitPrice' => $i['unit_price'],
                'discount' => $i['discount_amount'], 'taxRate' => $i['tax_rate'], 'tax' => $i['tax_amount'], 'subtotal' => $i['subtotal'], 'total' => $i['total'],
            ], $items),
        ];
    }

    /** @param list<array<string, mixed>> $items @return list<array<string, mixed>> */
    private function shipments(string $orderId, array $items): array
    {
        $names = array_column($items, 'product_name', 'id');

        return array_map(static fn (array $s) => [
            'id' => $s['id'], 'carrier' => $s['carrier'], 'service' => $s['service'], 'status' => $s['status'], 'trackingNumber' => $s['tracking_number'], 'trackingUrl' => $s['tracking_url'],
            'shippedAt' => $s['shipped_at'], 'deliveredAt' => $s['delivered_at'], 'estimatedDeliveryAt' => $s['estimated_delivery_at'],
            'items' => array_map(static fn (array $l) => ['orderItemId' => $l['order_item_id'], 'name' => $names[$l['order_item_id']] ?? '', 'quantity' => $l['quantity']], $s['items']),
            'events' => array_map(static fn (array $e) => ['status' => $e['status'], 'description' => $e['description'], 'location' => $e['location'], 'at' => $e['occurred_at']], $s['events']),
        ], $this->shipments->forOrder($orderId));
    }
}
