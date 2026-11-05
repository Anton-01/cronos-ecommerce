<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Brick\Math\BigDecimal;
use Cronos\Inventory\Domain\Exceptions\Conflict;
use Cronos\Inventory\Domain\Exceptions\InsufficientStock;
use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Cronos\Inventory\Domain\Exceptions\NotFound;
use Cronos\Inventory\Domain\Ports\LocationRepository;
use Cronos\Inventory\Domain\Ports\OperationsStore;
use Cronos\Inventory\Domain\Ports\WarehouseRepository;
use Cronos\Inventory\Domain\Services\Quantities;
use Cronos\Inventory\Domain\ValueObjects\LocationMovementType as L;

/**
 * Blind counts. A session freezes what the system held when it started; each count is compared with THAT figure, and whatever moved on the
 * shelf since (a pick, a delivery) is kept, not overwritten: target = counted + what moved meanwhile, so the correction is `counted - frozen`.
 * The first count of a shelf+product is applied; a different second count is not summed, it waits for a lead.
 */
final class CountSessions
{
    public function __construct(
        private readonly OperationsStore $store,
        private readonly LocationRepository $locations,
        private readonly WarehouseRepository $warehouses,
        private readonly WarehouseOperations $operations,
    ) {}

    /** @return string session id */
    public function open(int $warehouseId, string $name, Actor $actor, ?string $scopeLocationId = null): string
    {
        $this->warehouses->find($warehouseId) ?? throw new NotFound('The warehouse');
        $name = trim($name);
        ($name !== '' && mb_strlen($name) <= 120) || throw new InvalidMovement('A count needs a name (up to 120 characters).', 'INVALID_NAME');
        if ($scopeLocationId !== null) {
            ($this->locations->find($scopeLocationId)['warehouse_id'] ?? null) === $warehouseId || throw new InvalidMovement('That location is not in this warehouse.', 'LOCATION_WAREHOUSE_MISMATCH');
        }

        return $this->store->atomically(function () use ($warehouseId, $name, $actor, $scopeLocationId): string {
            $id = $this->store->createSession(['warehouse_id' => $warehouseId, 'name' => $name, 'scope_location_id' => $scopeLocationId, 'created_by' => $actor->userId, 'created_at' => now()]);
            $this->store->snapshotSession($id, $warehouseId, $scopeLocationId);

            return $id;
        });
    }

    /** @return array{id: string, status: string, movement: ?string} */
    public function submit(string $sessionId, string $productId, string $locationId, string|int|float $counted, Actor $actor, ?string $idempotencyKey = null, ?string $note = null): array
    {
        $counted = Quantities::of((string) $counted);
        $counted->isNegative() && throw new InvalidMovement('A count cannot be negative.', 'INVALID_QUANTITY');

        return $this->store->atomically(function () use ($sessionId, $productId, $locationId, $counted, $actor, $idempotencyKey, $note): array {
            if ($idempotencyKey !== null && ($seen = $this->store->entryByKey($idempotencyKey)) !== null) {
                return ['id' => $seen['id'], 'status' => $seen['status'], 'movement' => $seen['movement_ulid']];
            }
            $this->store->advisoryLock("count:{$sessionId}:{$productId}:{$locationId}");
            $session = $this->store->findSession($sessionId) ?? throw new NotFound('The count');
            $session['status'] === 'open' || throw new Conflict('That count is closed.', 'COUNT_CLOSED');
            $location = $this->locations->find($locationId) ?? throw new NotFound('The location');
            (int) $session['warehouse_id'] === $location['warehouse_id'] || throw new InvalidMovement('That location is not in the warehouse being counted.', 'LOCATION_WAREHOUSE_MISMATCH');
            ($session['scope_location_id'] === null || in_array($locationId, $this->locations->subtreeIds($session['scope_location_id']), true)) || throw new InvalidMovement('That location is outside this count.', 'OUTSIDE_COUNT_SCOPE');

            $previous = $this->store->entriesFor($sessionId, $productId, $locationId);
            $frozen = Quantities::of($this->store->snapshotQuantity($sessionId, $productId, $locationId));
            $row = ['session_id' => $sessionId, 'product_id' => $productId, 'location_id' => $locationId, 'counted' => (string) $counted, 'system_at_start' => (string) $frozen,
                'idempotency_key' => $idempotencyKey, 'counted_by' => $actor->userId, 'counted_at' => now(), 'note' => $note === null ? null : mb_substr($note, 0, 500)];

            if ($previous === []) {
                [$status, $movement, $why] = $this->applyOrHold($sessionId, $productId, $locationId, $counted->minus($frozen), $actor);
            } else {
                $agrees = array_filter($previous, fn (array $e) => $e['status'] === 'confirmed' && BigDecimal::of($e['counted'])->isEqualTo($counted)) !== [];
                [$status, $movement, $why] = $agrees ? ['confirmed', null, null] : ['in_review', null, 'A different count was already taken for this shelf.'];
            }
            $row['note'] ??= $why;
            $id = $this->store->createEntry($row + ['status' => $status, 'movement_ulid' => $movement]);

            return ['id' => $id, 'status' => $status, 'movement' => $movement];
        });
    }

    /** A lead settles a count that disagreed: accept it (apply it) or reject it. @return ?string the adjustment's ulid */
    public function review(string $entryId, bool $approve, Actor $lead, ?string $note = null): ?string
    {
        return $this->store->atomically(function () use ($entryId, $approve, $lead, $note): ?string {
            $entry = $this->store->findEntry($entryId) ?? throw new NotFound('The count');
            $this->store->advisoryLock("count:{$entry['session_id']}:{$entry['product_id']}:{$entry['location_id']}");
            $entry = $this->store->findEntry($entryId, lock: true);
            $entry['status'] === 'in_review' || throw new Conflict('That count was already settled.', 'ALREADY_DECIDED');
            if (! $approve) {
                $this->store->reviewEntry($entryId, 'rejected', $lead->userId, $note, null);

                return null;
            }
            $applied = BigDecimal::of($this->store->appliedCountDelta($entry['session_id'], $entry['product_id'], $entry['location_id']));
            $delta = BigDecimal::of($entry['counted'])->minus($entry['system_at_start'])->minus($applied);
            $movement = $delta->isZero() ? null : $this->operations->adjust($entry['product_id'], $entry['location_id'], $delta, $lead, L::COUNT_ADJUST, 'count_session', $entry['session_id'], 'count:'.$entryId, 'Count review');
            $this->store->reviewEntry($entryId, 'confirmed', $lead->userId, $note, $movement);

            return $movement;
        });
    }

    public function close(string $sessionId, Actor $actor): void
    {
        $this->store->atomically(function () use ($sessionId, $actor): void {
            $session = $this->store->findSession($sessionId) ?? throw new NotFound('The count');
            $session['status'] === 'open' || throw new Conflict('That count is already closed.', 'COUNT_CLOSED');
            $this->store->entriesInReview($sessionId) === 0 || throw new Conflict('Settle the counts that are waiting for review first.', 'COUNTS_IN_REVIEW');
            $this->store->closeSession($sessionId, $actor->userId);
        });
    }

    /** @return array{0: string, 1: ?string, 2: ?string} status, movement ulid, why it was held */
    private function applyOrHold(string $sessionId, string $productId, string $locationId, BigDecimal $delta, Actor $actor): array
    {
        if ($delta->isZero()) {
            return ['confirmed', null, null];
        }
        try {
            // A savepoint: if the books or the shelf refuse, nothing of this attempt stays, and the count simply goes to a lead.
            $ulid = $this->store->atomically(fn (): string => $this->operations->adjust($productId, $locationId, $delta, $actor, L::COUNT_ADJUST, 'count_session', $sessionId, null, 'Blind count'));

            return ['confirmed', $ulid, null];
        } catch (InsufficientStock $e) {
            return ['in_review', null, 'The correction could not be applied: '.$e->getMessage()];
        }
    }
}
