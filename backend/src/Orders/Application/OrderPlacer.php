<?php

declare(strict_types=1);

namespace Cronos\Orders\Application;

use Brick\Math\BigDecimal;
use Cronos\Checkout\Domain\Ports\CheckoutRepository;
use Cronos\Orders\Domain\Events\OrderPlaced;
use Cronos\Orders\Domain\Exceptions\OrderError;
use Cronos\Orders\Domain\Ports\OrderRepository;
use Cronos\Shared\Domain\DomainEventDispatcher;
use Illuminate\Support\Str;

/**
 * Turns a paid checkout into the permanent order. Idempotent on the checkout id: the listener may run twice (the outbox delivers at least
 * once) and still produces exactly one order.
 */
final class OrderPlacer
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly CheckoutRepository $checkouts,
        private readonly DomainEventDispatcher $events,
    ) {}

    /** @return array<string, mixed> the order */
    public function fromCheckout(string $checkoutId): array
    {
        $existing = $this->orders->findByCheckout($checkoutId);
        if ($existing !== null) {
            return $existing;
        }

        $placed = $this->orders->atomically(function () use ($checkoutId): ?array {
            $checkout = $this->checkouts->lock($checkoutId) ?? throw new OrderError('That checkout does not exist.', 'CHECKOUT_NOT_FOUND');
            if ($this->orders->findByCheckout($checkoutId) !== null) {
                return null; // another worker got here first
            }
            $checkout['status'] === 'paid' || throw new OrderError('Only a paid checkout becomes an order.', 'CHECKOUT_NOT_PAID');

            $id = (string) Str::ulid();
            $number = $this->orders->nextNumber();
            $order = [
                'id' => $id, 'order_number' => $number, 'order_type' => 'standard', 'user_id' => $checkout['user_id'], 'email' => $checkout['email'],
                'market_code' => $checkout['market_code'], 'currency' => $checkout['currency'], 'tax_included' => $checkout['tax_included'],
                'status' => 'processing', 'payment_status' => 'paid', 'fulfillment_status' => 'unfulfilled',
                'subtotal' => $checkout['subtotal'], 'discount_total' => (string) BigDecimal::of($checkout['items_discount'])->plus($checkout['shipping_discount'])->toScale(2),
                'shipping_total' => $checkout['shipping'],
                'tax_total' => $checkout['tax'], 'grand_total' => $checkout['total'], 'coupon_id' => $checkout['coupon_id'], 'coupon_code' => $checkout['coupon_code'],
                'checkout_id' => $checkout['id'], 'payment_id' => $checkout['payment_id'], 'fraud_score' => $checkout['fraud_score'], 'fraud_decision' => $checkout['fraud_decision'],
                'ip_address' => $checkout['ip_address'], 'country_code' => $checkout['country_code'], 'timezone' => $checkout['timezone'], 'placed_at' => $checkout['paid_at'],
            ];
            $items = array_map(static fn (array $l) => [
                'product_id' => $l['product_id'], 'product_name' => $l['name'], 'sku' => $l['sku'], 'unit' => $l['unit'] ?? ($l['by_roll'] ? 'roll' : 'piece'),
                'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'], 'discount_amount' => $l['discount'], 'tax_rate' => $l['tax_rate'] ?? '0', 'tax_amount' => $l['tax'],
                'subtotal' => $l['line_total'], 'total' => $l['total'], 'options' => ['by_roll' => $l['by_roll']],
            ], $checkout['lines']);
            $discounts = array_map(static fn (array $a) => ['source' => $a['kind'], 'reference_id' => $a['id'], 'name' => $a['name'], 'amount' => $a['amount']], $checkout['applied_discounts']);

            $this->orders->insert($order, $items, ['shipping' => $checkout['shipping_address'], 'billing' => $checkout['billing_address']], $discounts);
            $this->orders->addHistory($id, ['status' => 'processing', 'payment_status' => 'paid', 'note' => 'Order placed after payment '.$checkout['payment_id'], 'notify_customer' => true, 'ip_address' => $checkout['ip_address'], 'country_code' => $checkout['country_code'], 'timezone' => $checkout['timezone']]);

            return $this->orders->find($id);
        });

        if ($placed === null) {
            return $this->orders->findByCheckout($checkoutId);
        }
        $this->events->dispatch(new OrderPlaced($placed['id'], $placed['order_number'], $placed['user_id'], $placed['email'], $placed['grand_total'], $placed['currency']));

        return $placed;
    }
}
