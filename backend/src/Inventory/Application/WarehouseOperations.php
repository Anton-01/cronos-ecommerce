<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Brick\Math\BigDecimal;
use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Cronos\Inventory\Domain\Exceptions\NotFound;
use Cronos\Inventory\Domain\Ports\LocationRepository;
use Cronos\Inventory\Domain\Ports\OperationsStore;
use Cronos\Inventory\Domain\Services\Quantities;
use Cronos\Inventory\Domain\ValueObjects\LocationMovementType as L;
use Cronos\Inventory\Domain\ValueObjects\MovementType;

/**
 * What the warehouse team does with stock. Two ledgers are involved and the order of locks is always the same, so nothing here can deadlock
 * with a purchase or with another warehouse job:
 *
 *   named (advisory) lock  →  warehouse level (books)  →  shelf rows in ascending id
 *
 * A purchase only ever takes the warehouse level, briefly. Moving stock between shelves and picking an order never touch the books at all.
 */
final class WarehouseOperations
{
    public function __construct(
        private readonly OperationsStore $store,
        private readonly StockLedger $books,
        private readonly LocationLedger $shelves,
        private readonly LocationRepository $locations,
    ) {}

    /** Stock arrives: the books and the shelf both go up. Without a location it goes to RECEPCION until it is put away. */
    public function receive(string $productId, int $warehouseId, string|int|float $amount, Actor $actor, ?string $locationId = null, ?string $unitCost = null, ?string $idempotencyKey = null, ?string $note = null): string
    {
        $location = $this->locationOf($warehouseId, $locationId);

        return $this->once($idempotencyKey, fn (): string => $this->apply($productId, $location, BigDecimal::of((string) Quantities::positive($amount)), $actor, L::RECEIVE, MovementType::PURCHASE, $unitCost, null, null, $idempotencyKey, null, $note));
    }

    /** Scrap, breakage, a cut that was thrown away: leaves the shelf and the books. */
    public function writeOff(string $productId, string $locationId, string|int|float $amount, Actor $actor, string $reason, ?string $idempotencyKey = null, ?int $expectedVersion = null): string
    {
        trim($reason) !== '' || throw new InvalidMovement('Say why it is written off.', 'REASON_REQUIRED');
        $location = $this->locations->find($locationId) ?? throw new NotFound('The location');

        return $this->once($idempotencyKey, fn (): string => $this->apply($productId, $location, BigDecimal::of((string) Quantities::positive($amount))->negated(), $actor, L::WASTE, MovementType::WASTE, null, null, null, $idempotencyKey, $expectedVersion, $reason));
    }

    /** Something comes back to a shelf after an order was already picked. The books were returned by the order cancellation itself. */
    public function returnToShelf(string $productId, string $locationId, string|int|float $amount, Actor $actor, ?string $orderId = null, ?string $idempotencyKey = null, ?string $note = null): string
    {
        return $this->shelves->record($productId, $locationId, L::RETURN, $amount, $actor, idempotencyKey: $idempotencyKey, referenceType: $orderId === null ? null : 'order_return', referenceId: $orderId, note: $note);
    }

    /**
     * A correction of a shelf (count or approved request): the shelf and the books move by the same difference, never by overwriting.
     * Refuses what the books cannot give up (units customers already reserved).
     *
     * @param  string  $locType  adjust | count_adjust
     */
    public function adjust(string $productId, string $locationId, string|BigDecimal $delta, Actor $actor, string $locType, string $referenceType, string $referenceId, ?string $idempotencyKey, ?string $note): string
    {
        $location = $this->locations->find($locationId) ?? throw new NotFound('The location');
        $signed = BigDecimal::of((string) $delta);
        $signed->isZero() && throw new InvalidMovement('There is nothing to adjust.', 'NO_CHANGE');

        return $this->once($idempotencyKey, fn (): string => $this->apply($productId, $location, $signed, $actor, $locType, MovementType::ADJUSTMENT, null, $referenceType, $referenceId, $idempotencyKey, null, $note));
    }

    /** @param callable(): string $work */
    private function once(?string $key, callable $work): string
    {
        return $this->store->atomically(function () use ($key, $work): string {
            if ($key !== null) {
                $this->store->advisoryLock('idem:'.$key);
                if (($seen = $this->shelves->replayOf($key)) !== null) {
                    return $seen;
                }
            }

            return $work();
        });
    }

    /**
     * @param  array<string, mixed>  $location
     * @return string ulid of the shelf row
     */
    private function apply(string $productId, array $location, BigDecimal $signed, Actor $actor, string $locType, string $bookType, ?string $unitCost, ?string $refType, ?string $refId, ?string $key, ?int $expectedVersion, ?string $note): string
    {
        $up = $signed->isPositive();
        $amount = $signed->abs();
        // 1) the books (takes the warehouse-level lock, validates reservations) ...
        $this->books->record($productId, $location['warehouse_id'], $bookType, $amount, $actor, increase: $bookType === MovementType::ADJUSTMENT ? $up : null, unitCost: $unitCost, referenceType: $refType ?? 'warehouse', referenceId: $refId, note: $note);

        // 2) ... then the shelf.
        return $this->shelves->record($productId, $location['id'], $locType, $amount, $actor, increase: in_array($locType, L::EITHER, true) ? $up : null, idempotencyKey: $key, expectedVersion: $expectedVersion, referenceType: $refType, referenceId: $refId, note: $note);
    }

    /** @return array<string, mixed> */
    private function locationOf(int $warehouseId, ?string $locationId): array
    {
        $location = $locationId === null
            ? ($this->locations->findByCode($warehouseId, 'RECEPCION') ?? throw new NotFound('The receiving area'))
            : ($this->locations->find($locationId) ?? throw new NotFound('The location'));
        $location['warehouse_id'] === $warehouseId || throw new InvalidMovement('That location belongs to another warehouse.', 'LOCATION_WAREHOUSE_MISMATCH');

        return $location;
    }
}
