<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Brick\Math\BigDecimal;
use Cronos\Inventory\Domain\Events\StockChanged;
use Cronos\Inventory\Domain\Exceptions\InsufficientStock;
use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Cronos\Inventory\Domain\Exceptions\NotFound;
use Cronos\Inventory\Domain\Ports\ProductStockInfo;
use Cronos\Inventory\Domain\Ports\ReservationStore;
use Cronos\Inventory\Domain\Ports\StockStore;
use Cronos\Inventory\Domain\Ports\WarehouseRepository;
use Cronos\Inventory\Domain\Services\Quantities;
use Cronos\Inventory\Domain\ValueObjects\MovementType;
use Cronos\Shared\Domain\DomainEventDispatcher;
use DateTimeImmutable;

/**
 * Holds stock for a cart or an order for a while, so two shoppers cannot both buy the last roll, then either sells it
 * (consume: a Kardex movement) or gives it back (release / expiry). A reservation never touches the Kardex until it is consumed.
 */
final class ReservationService
{
    public function __construct(
        private readonly StockStore $store,
        private readonly ReservationStore $reservations,
        private readonly WarehouseRepository $warehouses,
        private readonly ProductStockInfo $products,
        private readonly StockLedger $ledger,
        private readonly DomainEventDispatcher $events,
    ) {}

    /**
     * Reserves every item or none (one failure rolls the whole thing back). Calling again for the same holder and product
     * replaces the amount instead of piling up, so a cart that changes quantity is not charged twice.
     *
     * @param  list<array{product_id: string, quantity: string|int|float}>  $items
     * @return list<string> reservation ids
     */
    public function reserve(string $holderType, string $holderId, array $items, int $ttlMinutes = 15, ?string $marketCode = null): array
    {
        in_array($holderType, ['cart', 'order'], true) || throw new InvalidMovement('A reservation belongs to a cart or an order.', 'INVALID_HOLDER');
        $ttlMinutes >= 1 || throw new InvalidMovement('A reservation lasts at least one minute.', 'INVALID_TTL');
        usort($items, fn (array $a, array $b) => $a['product_id'] <=> $b['product_id']); // same lock order for everyone: no deadlocks
        $expiresAt = new DateTimeImmutable("+{$ttlMinutes} minutes");

        $ids = $this->store->atomically(function () use ($holderType, $holderId, $items, $expiresAt, $marketCode): array {
            $ids = [];
            foreach ($items as $item) {
                $ids[] = $this->reserveOne($holderType, $holderId, $item['product_id'], Quantities::positive($item['quantity']), $expiresAt, $marketCode);
            }

            return $ids;
        });

        foreach (array_unique(array_column($items, 'product_id')) as $productId) {
            $this->events->dispatch(new StockChanged($productId));
        }

        return $ids;
    }

    /** Gives everything a holder reserved back to the shelf. */
    public function release(string $holderType, string $holderId): int
    {
        return $this->resolveAll($this->reservations->activeOf($holderType, $holderId), 'released');
    }

    /** Reservations past their expiry (called every minute by the scheduler). */
    public function expireDue(?DateTimeImmutable $now = null, int $batch = 500): int
    {
        $total = 0;
        do {
            $due = $this->reservations->due($now ?? new DateTimeImmutable, $batch);
            $total += $this->resolveAll($due, 'expired');
        } while (count($due) === $batch);

        return $total;
    }

    /** The shopper paid: the reserved stock becomes a sale in the Kardex. @return list<string> movement ulids */
    public function consume(string $holderType, string $holderId, string $orderId, Actor $actor): array
    {
        $movements = $this->store->atomically(function () use ($holderType, $holderId, $orderId, $actor): array {
            $active = $this->reservations->activeOf($holderType, $holderId);
            $active !== [] || throw new NotFound('The reservation');
            usort($active, fn (array $a, array $b) => [$a['product_id'], $a['warehouse_id']] <=> [$b['product_id'], $b['warehouse_id']]);

            $ulids = [];
            foreach ($active as $r) {
                $ulids[] = $this->ledger->record($r['product_id'], (int) $r['warehouse_id'], MovementType::RESERVATION_CONSUME, $r['quantity'], $actor, referenceType: 'order', referenceId: $orderId, releaseReserved: $r['quantity']);
                $this->reservations->resolve($r['id'], 'consumed');
            }

            return $ulids;
        });

        return $movements;
    }

    /** More time for a cart that is still being used. */
    public function extend(string $holderType, string $holderId, int $ttlMinutes = 15): void
    {
        $expiresAt = new DateTimeImmutable("+{$ttlMinutes} minutes");
        foreach ($this->reservations->activeOf($holderType, $holderId) as $r) {
            $this->reservations->setQuantity($r['id'], $r['quantity'], $expiresAt);
        }
    }

    private function reserveOne(string $holderType, string $holderId, string $productId, BigDecimal $quantity, DateTimeImmutable $expiresAt, ?string $marketCode): string
    {
        $product = $this->products->find($productId) ?? throw new NotFound('The product');
        (! in_array($product['type'], ['variable', 'grouped'], true) && $product['manage_stock'])
            || throw new InvalidMovement('This product does not keep stock of its own.', 'PRODUCT_DOES_NOT_TRACK_STOCK');

        $candidates = $this->warehouses->candidatesFor($marketCode);
        // Lock every candidate in id order first (two carts from different markets try warehouses in different orders).
        $locked = [];
        $ids = array_map(fn (array $w) => (int) $w['id'], $candidates);
        sort($ids);
        foreach ($ids as $id) {
            $locked[$id] = $this->store->lockLevel($productId, $id);
        }

        $shortest = null;
        foreach ($candidates as $warehouse) {
            $level = $locked[(int) $warehouse['id']];
            $onHand = Quantities::of($level['on_hand']);
            $reserved = Quantities::of($level['reserved']);
            $existing = $this->reservations->active($holderType, $holderId, $productId, (int) $warehouse['id']);
            $mine = $existing === null ? Quantities::zero() : Quantities::of($existing['quantity']);
            $freeForMe = $onHand->minus($reserved)->plus($mine);

            if (! $product['allow_backorder'] && $freeForMe->isLessThan($quantity)) {
                $shortest = $freeForMe->isGreaterThan($shortest ?? Quantities::zero()) ? $freeForMe : ($shortest ?? $freeForMe);

                continue;
            }

            $this->store->saveLevel($productId, (int) $warehouse['id'], (string) $onHand, (string) $reserved->minus($mine)->plus($quantity));
            $this->store->refreshProductStock($productId);

            if ($existing !== null) {
                $this->reservations->setQuantity($existing['id'], (string) $quantity, $expiresAt);

                return $existing['id'];
            }

            return $this->reservations->insert($productId, (int) $warehouse['id'], (string) $quantity, $holderType, $holderId, $expiresAt);
        }

        throw new InsufficientStock((string) $quantity, (string) ($shortest ?? Quantities::zero()), $product['sku']);
    }

    /** @param list<array<string, mixed>> $reservations */
    private function resolveAll(array $reservations, string $status): int
    {
        if ($reservations === []) {
            return 0;
        }
        usort($reservations, fn (array $a, array $b) => [$a['product_id'], $a['warehouse_id']] <=> [$b['product_id'], $b['warehouse_id']]);

        $this->store->atomically(function () use ($reservations, $status): void {
            foreach ($reservations as $r) {
                $level = $this->store->lockLevel($r['product_id'], (int) $r['warehouse_id']);
                $left = Quantities::of($level['reserved'])->minus(Quantities::of($r['quantity']));
                $this->store->saveLevel($r['product_id'], (int) $r['warehouse_id'], $level['on_hand'], (string) ($left->isNegative() ? Quantities::zero() : $left));
                $this->store->refreshProductStock($r['product_id']);
                $this->reservations->resolve($r['id'], $status);
            }
        });

        foreach (array_unique(array_column($reservations, 'product_id')) as $productId) {
            $this->events->dispatch(new StockChanged($productId));
        }

        return count($reservations);
    }
}
