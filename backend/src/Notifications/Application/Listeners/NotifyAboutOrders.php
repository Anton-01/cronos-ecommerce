<?php

declare(strict_types=1);

namespace Cronos\Notifications\Application\Listeners;

use Cronos\CustomOrders\Domain\Events\CustomOrderSubmitted;
use Cronos\CustomOrders\Domain\Events\CustomOrderUpdated;
use Cronos\CustomOrders\Domain\Events\QuoteSent;
use Cronos\Notifications\Application\NotificationService;
use Cronos\Orders\Domain\Events\OrderPlaced;
use Cronos\Orders\Domain\Events\OrderStatusChanged;
use Cronos\Orders\Domain\Ports\OrderRepository;
use Cronos\Payments\Domain\Events\PaymentNeedsReview;
use Cronos\Shipping\Domain\Events\ShipmentCreated;

/** Turns order, shipping and payment events into customer (and, for anomalies, operator) messages. Idempotent through the log's dedupe keys. */
final class NotifyAboutOrders
{
    /** Statuses worth a message of their own. `shipped` is announced by the shipment (it has the tracking link). */
    private const STATUS_MESSAGES = ['delivered', 'cancelled', 'refunded'];

    public function __construct(private readonly NotificationService $notifications, private readonly OrderRepository $orders) {}

    public function placed(OrderPlaced $e): void
    {
        $o = $this->orders->find($e->orderId);
        if ($o === null) {
            return;
        }
        $this->notifications->send('order_placed', 'order:'.$o['id'], $o['email'], $o['user_id'], $o['market_code'], $this->orderData($o) + [
            'items' => array_map(static fn (array $i) => ['name' => $i['product_name'], 'quantity' => $i['quantity'], 'unit' => $i['unit'], 'total' => $i['total']], $this->orders->items($o['id'])),
        ], 'order', $o['id']);
    }

    public function statusChanged(OrderStatusChanged $e): void
    {
        if (! $e->notifyCustomer || ! in_array($e->to, self::STATUS_MESSAGES, true) || $e->from === $e->to) {
            return;
        }
        $o = $this->orders->find($e->orderId);
        $o === null || $this->notifications->send('order_status', "order:{$o['id']}:{$e->to}", $o['email'], $o['user_id'], $o['market_code'], $this->orderData($o) + ['status' => $e->to], 'order', $o['id']);
    }

    public function shipped(ShipmentCreated $e): void
    {
        $o = $this->orders->find($e->orderId);
        $o === null || $this->notifications->send('shipment_created', 'shipment:'.$e->shipmentId, $o['email'], $o['user_id'], $o['market_code'], $this->orderData($o) + [
            'carrier' => strtoupper($e->carrier), 'tracking_number' => $e->trackingNumber, 'tracking_url' => $e->trackingUrl, 'complete' => $e->completesOrder,
        ], 'shipment', $e->shipmentId);
    }

    public function quoteSent(QuoteSent $e): void
    {
        $this->notifications->send('quote_ready', 'quote:'.$e->quoteId, $e->email, $e->userId, $e->marketCode, [
            'number' => $e->number, 'total' => $e->total, 'currency' => $e->currency, 'valid_until' => $e->validUntil ?? '-', 'url' => $this->customUrl($e->number),
        ], 'custom_order', $e->requestId);
    }

    public function customUpdated(CustomOrderUpdated $e): void
    {
        $this->notifications->send('custom_order_update', $e->dedupeKey, $e->email, $e->userId, $e->marketCode, ['number' => $e->number, 'message' => $e->message, 'url' => $this->customUrl($e->number)], 'custom_order', $e->requestId);
    }

    public function customSubmitted(CustomOrderSubmitted $e): void
    {
        $ops = (string) config('notifications.ops_email', '');
        $ops === '' || $this->notifications->send('ops_alert', 'custom:'.$e->requestId, $ops, null, null, ['subject_line' => "New custom order {$e->number}", 'detail' => "{$e->title} — {$e->email}"], 'custom_order', $e->requestId);
    }

    private function customUrl(string $number): string
    {
        return rtrim((string) config('app.storefront_url'), '/').'/account/custom-orders/'.rawurlencode($number);
    }

    public function paymentNeedsReview(PaymentNeedsReview $e): void
    {
        $ops = (string) config('notifications.ops_email', '');
        $ops === '' || $this->notifications->send('ops_alert', "payment:{$e->paymentId}:{$e->reason}", $ops, null, null, [
            'subject_line' => "Payment {$e->paymentId} needs review", 'detail' => "{$e->payableType}:{$e->payableId} — {$e->reason}",
        ], 'payment', $e->paymentId);
    }

    /** @param array<string, mixed> $o @return array<string, mixed> */
    private function orderData(array $o): array
    {
        return [
            'number' => $o['order_number'], 'total' => $o['grand_total'], 'currency' => $o['currency'], 'status' => $o['status'],
            'track_url' => rtrim((string) config('app.storefront_url'), '/').'/orders/track?number='.rawurlencode($o['order_number']),
        ];
    }
}
