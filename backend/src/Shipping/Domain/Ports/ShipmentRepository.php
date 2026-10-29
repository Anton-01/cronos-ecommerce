<?php

declare(strict_types=1);

namespace Cronos\Shipping\Domain\Ports;

interface ShipmentRepository
{
    public function atomically(callable $work): mixed;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @return array<string, mixed>|null */
    public function lock(string $id): ?array;

    /** @return array<string, mixed>|null */
    public function findByTracking(string $carrier, string $trackingNumber): ?array;

    /**
     * @param  array<string, mixed>  $shipment
     * @param  list<array{order_item_id: int, quantity: string}>  $items
     * @param  list<array<string, mixed>>  $packages
     */
    public function insert(array $shipment, array $items, array $packages): void;

    /** @param array<string, mixed> $changes */
    public function update(string $id, array $changes): void;

    /** @return list<array<string, mixed>> with items and events, oldest first */
    public function forOrder(string $orderId): array;

    /** @return array<int, string> order_item_id => quantity already on a live (not cancelled) shipment */
    public function shippedQuantities(string $orderId): array;

    /** @param array<string, mixed> $event @return bool false when this event was already recorded */
    public function addEvent(string $shipmentId, array $event): bool;

    /** True when the order has shipments and every live one is delivered. */
    public function allDelivered(string $orderId): bool;
}
