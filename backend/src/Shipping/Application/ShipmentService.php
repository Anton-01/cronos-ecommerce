<?php

declare(strict_types=1);

namespace Cronos\Shipping\Application;

use Brick\Math\BigDecimal;
use Cronos\Orders\Application\OrderService;
use Cronos\Orders\Domain\Ports\OrderRepository;
use Cronos\Orders\Domain\Services\OrderStateMachine as M;
use Cronos\Shared\Domain\ClientContext;
use Cronos\Shared\Domain\DomainEventDispatcher;
use Cronos\Shipping\Domain\Events\ShipmentCreated;
use Cronos\Shipping\Domain\Events\ShipmentDelivered;
use Cronos\Shipping\Domain\Exceptions\ShippingError;
use Cronos\Shipping\Domain\Ports\ShipmentRepository;
use Cronos\Shipping\Domain\Services\ShipmentStatus as S;
use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * Packs an order into one or more shipments and follows them to the door. Whatever the carrier tells us is stored as an immutable event
 * first (replays are ignored) and only then moves the shipment forward; the order follows its shipments, never the other way round.
 */
final class ShipmentService
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly OrderRepository $orders,
        private readonly OrderService $orderService,
        private readonly DomainEventDispatcher $events,
    ) {}

    /**
     * @param  list<array{order_item_id: int, quantity: string}>  $items  empty = everything not yet shipped
     * @param  list<array<string, mixed>>  $packages
     * @return array<string, mixed> the shipment
     */
    public function create(string $orderId, string $carrier, ?string $service, ?string $trackingNumber, ?string $trackingUrl, array $items, array $packages, ?string $cost, ?DateTimeImmutable $estimatedAt, ?string $userId, ?ClientContext $client = null): array
    {
        $carrier = strtolower(trim($carrier));
        preg_match('/^[a-z0-9_-]{2,40}$/', $carrier) === 1 || throw new ShippingError('The carrier is a short code such as estafeta, dhl or fedex.', 'CARRIER_REQUIRED');
        $trackingNumber = $trackingNumber === null || trim($trackingNumber) === '' ? null : trim($trackingNumber);
        $trackingUrl === null || filter_var($trackingUrl, FILTER_VALIDATE_URL) !== false && str_starts_with($trackingUrl, 'http') || throw new ShippingError('The tracking link is not a valid URL.', 'INVALID_TRACKING_URL');
        $cost === null || (is_numeric($cost) && (float) $cost >= 0) || throw new ShippingError('The shipping cost must be zero or more.', 'INVALID_COST');

        $id = (string) Str::ulid();
        $fully = $this->shipments->atomically(function () use ($orderId, $carrier, $service, $trackingNumber, $trackingUrl, $items, $packages, $cost, $estimatedAt, $userId, $id): bool {
            $order = $this->orders->lock($orderId) ?? throw new ShippingError('That order does not exist.', 'ORDER_NOT_FOUND');
            ($order['status'] === M::PROCESSING && in_array($order['payment_status'], ['paid', 'partially_refunded'], true))
                || throw new ShippingError('Only a paid order that is being processed can be shipped.', 'ORDER_NOT_SHIPPABLE');
            $trackingNumber === null || $this->shipments->findByTracking($carrier, $trackingNumber) === null || throw new ShippingError('That tracking number is already in use.', 'DUPLICATE_TRACKING');

            $remaining = [];
            $already = $this->shipments->shippedQuantities($orderId);
            foreach ($this->orders->items($orderId) as $item) {
                $left = BigDecimal::of($item['quantity'])->minus($already[$item['id']] ?? '0');
                $left->isPositive() && $remaining[$item['id']] = $left;
            }
            $remaining !== [] || throw new ShippingError('Everything in this order is already on a shipment.', 'NOTHING_TO_SHIP');

            $lines = [];
            if ($items === []) {
                foreach ($remaining as $itemId => $left) {
                    $lines[] = ['order_item_id' => $itemId, 'quantity' => (string) $left];
                    $remaining[$itemId] = BigDecimal::zero();
                }
            } else {
                foreach ($items as $line) {
                    $left = $remaining[$line['order_item_id']] ?? throw new ShippingError('That line is not part of this order or is already fully shipped.', 'INVALID_ITEM');
                    (is_numeric($line['quantity']) && BigDecimal::of($line['quantity'])->isPositive() && BigDecimal::of($line['quantity'])->isLessThanOrEqualTo($left))
                        || throw new ShippingError("You can ship at most {$left} of that line.", 'INVALID_QUANTITY');
                    $lines[] = ['order_item_id' => $line['order_item_id'], 'quantity' => $line['quantity']];
                    $remaining[$line['order_item_id']] = $left->minus($line['quantity']);
                }
            }

            $now = new DateTimeImmutable;
            $this->shipments->insert([
                'id' => $id, 'order_id' => $orderId, 'carrier' => $carrier, 'service' => $service, 'status' => 'in_transit', 'tracking_number' => $trackingNumber,
                'tracking_url' => $trackingUrl, 'shipping_cost' => $cost, 'currency' => $cost === null ? null : $order['currency'], 'shipped_at' => $now->format('Y-m-d H:i:s.uP'),
                'estimated_delivery_at' => $estimatedAt?->format('Y-m-d H:i:sP'), 'created_by' => $userId,
            ], $lines, $packages);
            $this->shipments->addEvent($id, ['event_key' => 'created', 'status' => 'in_transit', 'description' => 'Shipment handed to '.$carrier, 'location' => null, 'occurred_at' => $now->format('Y-m-d H:i:s.uP'), 'raw' => null, 'source' => 'manual']);

            $stillLeft = array_filter($remaining, static fn (BigDecimal $q) => $q->isPositive());
            $fully = $stillLeft === [];
            $this->orders->update($orderId, ['fulfillment_status' => $fully ? 'shipped' : 'partial']);

            return $fully;
        });

        $order = $this->orders->find($orderId);
        $fully && $this->orderService->transition($orderId, M::SHIPPED, 'Shipped with '.$carrier.($trackingNumber ? " ({$trackingNumber})" : ''), true, $userId, $client);
        $this->events->dispatch(new ShipmentCreated($id, $orderId, $order['order_number'], $carrier, $trackingNumber, $trackingUrl, $fully));

        return $this->shipments->find($id);
    }

    /**
     * One carrier statement. Idempotent on (shipment, eventKey); returns false for a replay or an unknown tracking number.
     *
     * @param  array<string, mixed>|null  $raw
     */
    public function ingest(string $carrier, string $trackingNumber, string $eventKey, string $status, ?string $description, ?string $location, DateTimeImmutable $occurredAt, ?array $raw, string $source = 'webhook'): bool
    {
        S::isValid($status) && $status !== 'cancelled' || throw new ShippingError("Unknown shipment status [{$status}].", 'INVALID_STATUS');
        $shipment = $this->shipments->findByTracking($carrier, $trackingNumber);
        if ($shipment === null) {
            return false;
        }

        [$isNew, $delivered] = $this->shipments->atomically(function () use ($shipment, $eventKey, $status, $description, $location, $occurredAt, $raw, $source): array {
            $row = $this->shipments->lock($shipment['id']);
            $new = $this->shipments->addEvent($row['id'], [
                'event_key' => mb_substr($eventKey, 0, 120), 'status' => $status, 'description' => $description === null ? null : mb_substr($description, 0, 500),
                'location' => $location === null ? null : mb_substr($location, 0, 200), 'occurred_at' => $occurredAt->format('Y-m-d H:i:s.uP'), 'raw' => $raw, 'source' => $source,
            ]);
            if (! $new || ! S::advances($row['status'], $status)) {
                return [$new, null];
            }
            $changes = ['status' => $status];
            $status === 'delivered' && $changes['delivered_at'] = $occurredAt->format('Y-m-d H:i:s.uP');
            $this->shipments->update($row['id'], $changes);

            return [true, $status === 'delivered' ? $row : null];
        });

        if ($delivered !== null) {
            $this->events->dispatch(new ShipmentDelivered($delivered['id'], $delivered['order_id']));
            $this->closeOrderIfDelivered($delivered['order_id']);
        }

        return $isNew;
    }

    /** A person confirms delivery (the carrier has no webhook). */
    public function markDelivered(string $shipmentId, ?string $userId): void
    {
        $s = $this->shipments->find($shipmentId) ?? throw new ShippingError('That shipment does not exist.', 'SHIPMENT_NOT_FOUND');
        $s['tracking_number'] !== null
            ? $this->ingest($s['carrier'], $s['tracking_number'], 'manual-delivered', 'delivered', 'Marked as delivered by staff', null, new DateTimeImmutable, null, 'manual')
            : throw new ShippingError('Add a tracking number first.', 'TRACKING_REQUIRED');
    }

    private function closeOrderIfDelivered(string $orderId): void
    {
        $order = $this->orders->find($orderId);
        if ($order === null || $order['status'] !== M::SHIPPED || ! $this->shipments->allDelivered($orderId)) {
            return;
        }
        $this->orders->update($orderId, ['fulfillment_status' => 'delivered']);
        $this->orderService->transition($orderId, M::DELIVERED, 'All shipments delivered', true, null);
    }
}
