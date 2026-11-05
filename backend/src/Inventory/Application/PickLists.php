<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Brick\Math\BigDecimal;
use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Cronos\Inventory\Domain\Exceptions\NotFound;
use Cronos\Inventory\Domain\Ports\InventoryQueries;
use Cronos\Inventory\Domain\Ports\LocationRepository;
use Cronos\Inventory\Domain\Ports\OperationsStore;
use Cronos\Inventory\Domain\Services\Quantities;
use Cronos\Inventory\Domain\ValueObjects\LocationMovementType as L;

/**
 * "What still has to leave a shelf for a paid order". Nothing is stored: it is what the Kardex says was sold minus what came back minus
 * what the shelves already gave, so it can never drift. Confirming a pick only touches shelves; the books went down when the order was paid.
 */
final class PickLists
{
    public function __construct(
        private readonly OperationsStore $store,
        private readonly InventoryQueries $queries,
        private readonly LocationRepository $locations,
        private readonly LocationLedger $shelves,
    ) {}

    /** @return list<array{order_id: string, product_id: string, warehouse_id: int, pending: string}> */
    public function pending(?int $warehouseId = null, int $limit = 200): array
    {
        return array_map(static fn (array $l): array => [
            'order_id' => $l['order_id'], 'product_id' => $l['product_id'], 'warehouse_id' => $l['warehouse_id'],
            'pending' => (string) BigDecimal::of($l['sold'])->minus($l['returned'])->minus($l['picked'])->toScale(Quantities::SCALE),
        ], $this->store->pickLines($warehouseId, $limit));
    }

    /** @return string ulid of the shelf row */
    public function pick(string $orderId, string $productId, string $locationId, string|int|float $amount, Actor $actor, ?string $idempotencyKey = null): string
    {
        $qty = Quantities::positive($amount);

        return $this->store->atomically(function () use ($orderId, $productId, $locationId, $qty, $actor, $idempotencyKey): string {
            $this->store->advisoryLock('pick:'.$orderId);   // two people picking the same order line are taken one at a time
            if (($seen = $this->shelves->replayOf($idempotencyKey)) !== null) {
                return $seen;
            }
            $location = $this->locations->find($locationId) ?? throw new NotFound('The location');
            $location['is_pickable'] || throw new InvalidMovement('Stock is not picked from this location (put it away first).', 'LOCATION_NOT_PICKABLE');

            $line = null;
            foreach ($this->queries->soldForOrder($orderId) as $row) {
                $row['product_id'] === $productId && $row['warehouse_id'] === $location['warehouse_id'] && $line = $row;
            }
            $line !== null || throw new InvalidMovement('That order has nothing of this product to pick from this warehouse.', 'NOTHING_TO_PICK');
            $pending = BigDecimal::of($line['quantity'])->minus($line['returned'])->minus($this->store->pickedFor($orderId, $productId, $location['warehouse_id']));
            $qty->isLessThanOrEqualTo($pending) || throw new InvalidMovement("Only {$pending} still to pick for that order.", 'MORE_THAN_PENDING');

            return $this->shelves->record($productId, $locationId, L::PICK, $qty, $actor, idempotencyKey: $idempotencyKey, referenceType: 'order', referenceId: $orderId);
        });
    }
}
