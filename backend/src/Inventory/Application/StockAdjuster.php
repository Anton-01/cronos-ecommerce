<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Cronos\Inventory\Domain\Exceptions\Conflict;
use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Cronos\Inventory\Domain\Exceptions\NotFound;
use Cronos\Inventory\Domain\Ports\StockStore;
use Cronos\Inventory\Domain\Services\Quantities;
use Cronos\Inventory\Domain\ValueObjects\MovementType;
use Illuminate\Support\Str;

/** Human corrections of the Kardex: physical counts, warehouse transfers and compensating entries. */
final class StockAdjuster
{
    public function __construct(private readonly StockLedger $ledger, private readonly StockStore $store) {}

    /**
     * The shelf was counted: record the difference as an adjustment (nothing is overwritten).
     *
     * @return ?string ulid of the adjustment, null when the count matches the books
     */
    public function setCount(string $productId, int $warehouseId, string|int|float $counted, Actor $actor, ?string $note = null): ?string
    {
        $target = Quantities::of((string) $counted);
        $target->isNegative() && throw new InvalidMovement('A count cannot be negative.', 'INVALID_QUANTITY');

        return $this->store->atomically(function () use ($productId, $warehouseId, $target, $actor, $note): ?string {
            $current = Quantities::of($this->store->lockLevel($productId, $warehouseId)['on_hand']);
            $difference = $target->minus($current);
            if ($difference->isZero()) {
                return null;
            }

            return $this->ledger->record($productId, $warehouseId, MovementType::ADJUSTMENT, $difference->abs(), $actor, increase: $difference->isPositive(), referenceType: 'count', note: $note ?? 'Physical count');
        });
    }

    /** @return array{out: string, in: string} ulids of the two linked movements */
    public function transfer(string $productId, int $fromWarehouseId, int $toWarehouseId, string|int|float $amount, Actor $actor, ?string $note = null): array
    {
        $fromWarehouseId !== $toWarehouseId || throw new InvalidMovement('Pick two different warehouses.', 'SAME_WAREHOUSE');
        $reference = strtolower((string) Str::ulid());

        return $this->store->atomically(function () use ($productId, $fromWarehouseId, $toWarehouseId, $amount, $actor, $reference, $note): array {
            // Both levels are locked in a fixed order (lower warehouse id first) so two opposite transfers cannot deadlock.
            foreach ([min($fromWarehouseId, $toWarehouseId), max($fromWarehouseId, $toWarehouseId)] as $id) {
                $this->store->lockLevel($productId, $id);
            }

            return [
                'out' => $this->ledger->record($productId, $fromWarehouseId, MovementType::TRANSFER_OUT, $amount, $actor, referenceType: 'transfer', referenceId: $reference, note: $note),
                'in' => $this->ledger->record($productId, $toWarehouseId, MovementType::TRANSFER_IN, $amount, $actor, referenceType: 'transfer', referenceId: $reference, note: $note),
            ];
        });
    }

    /**
     * A mistake in the Kardex is never edited: a new movement undoes it and points back at it. Each movement can be undone once.
     *
     * @return string ulid of the compensating movement
     */
    public function compensate(string $movementUlid, string $reason, Actor $actor): string
    {
        trim($reason) !== '' || throw new InvalidMovement('Say why the movement is being compensated.', 'REASON_REQUIRED');

        return $this->store->atomically(function () use ($movementUlid, $reason, $actor): string {
            $movement = $this->store->findMovement($movementUlid) ?? throw new NotFound('The movement');
            $movement['reference_type'] !== 'movement' || throw new Conflict('A compensating movement cannot itself be compensated; record a new adjustment.', 'ALREADY_COMPENSATING');
            $this->store->compensationOf($movementUlid) === null || throw new Conflict('That movement was already compensated.', 'ALREADY_COMPENSATED');

            $signed = Quantities::of($movement['quantity']);

            return $this->ledger->record(
                $movement['product_id'], (int) $movement['warehouse_id'], MovementType::ADJUSTMENT, $signed->abs(), $actor, increase: $signed->isNegative(),
                unitCost: $movement['unit_cost'], referenceType: 'movement', referenceId: $movementUlid, note: 'Compensates '.$movementUlid.': '.trim($reason),
            );
        });
    }
}
