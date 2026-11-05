<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Brick\Math\BigDecimal;
use Cronos\Inventory\Domain\Events\LocationStockChanged;
use Cronos\Inventory\Domain\Exceptions\Conflict;
use Cronos\Inventory\Domain\Exceptions\InsufficientStock;
use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Cronos\Inventory\Domain\Exceptions\NotFound;
use Cronos\Inventory\Domain\Ports\LocationRepository;
use Cronos\Inventory\Domain\Ports\LocationStore;
use Cronos\Inventory\Domain\Ports\ProductStockInfo;
use Cronos\Inventory\Domain\Services\LocationMovementHasher;
use Cronos\Inventory\Domain\Services\Quantities;
use Cronos\Inventory\Domain\ValueObjects\LocationMovementType as T;
use Cronos\Shared\Domain\DomainEventDispatcher;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;

/**
 * The only way a shelf's stock changes: append a row to the location ledger and move the derived quantity, in one transaction with the
 * (product, location) row locked. Past rows are never edited; a mistake is answered with a compensation. This never touches the
 * warehouse-level `stock_levels` the purchase path uses, so warehouse work cannot slow or break a checkout.
 */
final class LocationLedger
{
    public function __construct(
        private readonly LocationStore $store,
        private readonly LocationRepository $locations,
        private readonly ProductStockInfo $products,
        private readonly LocationMovementHasher $hasher,
        private readonly DomainEventDispatcher $events,
    ) {}

    /**
     * @param  bool|null  $increase  required for adjust / count_adjust, ignored otherwise
     * @param  ?int  $expectedVersion  the version of the shelf row the screen saw; a different one means someone else moved it meanwhile
     * @return string ulid of the new row (the original one when the idempotency key was already used)
     */
    public function record(
        string $productId,
        string $locationId,
        string $type,
        string|int|float|BigDecimal $amount,
        Actor $actor,
        ?bool $increase = null,
        ?string $idempotencyKey = null,
        ?int $expectedVersion = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $note = null,
    ): string {
        in_array($type, T::ALL, true) && $type !== T::COMPENSATION || throw new InvalidMovement('Unknown location movement type.', 'INVALID_MOVEMENT_TYPE');
        $quantity = Quantities::positive($amount);
        if (in_array($type, T::LEAVES, true) || (in_array($type, T::EITHER, true) && $increase === false)) {
            $quantity = $quantity->negated();
        } elseif (in_array($type, T::EITHER, true) && $increase === null) {
            throw new InvalidMovement('Say whether the adjustment adds or removes.', 'INVALID_MOVEMENT');
        }
        [$product, $location] = $this->resolve($productId, $locationId, $quantity->isPositive());

        $result = $this->store->atomically(fn (): string => $this->write($product['id'], $location, $type, $quantity, $actor, null, $idempotencyKey, $expectedVersion, $referenceType, $referenceId, $note));
        $this->events->dispatch(new LocationStockChanged($productId, $location['warehouse_id'], $locationId));

        return $result;
    }

    /** The row an earlier request with this key produced, if any: lets a caller skip its own checks on a retry. */
    public function replayOf(?string $key): ?string
    {
        return $key === null ? null : ($this->store->findByIdempotencyKey($key)['ulid'] ?? null);
    }

    /**
     * Moves stock between two shelves of the same warehouse as two linked rows (out + in) in one transaction.
     *
     * @return array{out: string, in: string}
     */
    public function move(string $productId, string $fromId, string $toId, string|int|float|BigDecimal $amount, Actor $actor, ?string $idempotencyKey = null, ?int $expectedVersion = null, ?string $note = null): array
    {
        $fromId !== $toId || throw new InvalidMovement('Pick a different destination.', 'SAME_LOCATION');
        $qty = Quantities::positive($amount);
        [$product, $from] = $this->resolve($productId, $fromId, false);
        [, $to] = $this->resolve($productId, $toId, true);
        $from['warehouse_id'] === $to['warehouse_id'] || throw new InvalidMovement('A move stays inside one warehouse; use a warehouse transfer between warehouses.', 'LOCATION_WAREHOUSE_MISMATCH');
        $transfer = strtolower((string) Str::ulid());

        $rows = $this->store->atomically(function () use ($product, $from, $to, $qty, $actor, $idempotencyKey, $expectedVersion, $note, $transfer): array {
            // Fixed lock order (ascending id) so two opposite moves cannot deadlock each other.
            $ids = [$from['id'], $to['id']];
            sort($ids, SORT_STRING);
            foreach ($ids as $id) {
                $this->store->lockStock($product['id'], $id);
            }
            $out = $this->write($product['id'], $from, T::MOVE_OUT, $qty->negated(), $actor, $to['id'], $idempotencyKey === null ? null : $idempotencyKey.':out', $expectedVersion, 'location_transfer', $transfer, $note);
            $in = $this->write($product['id'], $to, T::MOVE_IN, $qty, $actor, $from['id'], $idempotencyKey === null ? null : $idempotencyKey.':in', null, 'location_transfer', $transfer, $note);

            return ['out' => $out, 'in' => $in];
        });
        $this->events->dispatch(new LocationStockChanged($productId, $from['warehouse_id'], $fromId));
        $this->events->dispatch(new LocationStockChanged($productId, $to['warehouse_id'], $toId));

        return $rows;
    }

    /** Undoes one row with a new row of the opposite sign. A row can be compensated once; the original stays untouched. */
    public function compensate(string $movementUlid, Actor $actor, string $note, ?string $idempotencyKey = null): string
    {
        $original = $this->store->findMovement($movementUlid) ?? throw new NotFound('The movement');
        $original['type'] !== T::COMPENSATION || throw new Conflict('A compensation cannot itself be compensated; record a new movement instead.', 'ALREADY_COMPENSATION');
        $this->store->compensationOf($movementUlid) === null || throw new Conflict('That movement was already compensated.', 'ALREADY_COMPENSATED');
        trim($note) !== '' || throw new InvalidMovement('Say why the movement is being undone.', 'NOTE_REQUIRED');
        $location = $this->locations->find($original['location_id']) ?? throw new NotFound('The location');

        $result = $this->store->atomically(function () use ($original, $location, $actor, $note, $idempotencyKey): string {
            $this->store->lockStock($original['product_id'], $original['location_id']);
            $this->store->compensationOf($original['ulid']) === null || throw new Conflict('That movement was already compensated.', 'ALREADY_COMPENSATED');

            return $this->write($original['product_id'], $location, T::COMPENSATION, BigDecimal::of($original['quantity'])->negated(), $actor, $original['counterpart_location_id'], $idempotencyKey, null, 'location_movement', $original['ulid'], $note);
        });
        $this->events->dispatch(new LocationStockChanged($original['product_id'], $location['warehouse_id'], $original['location_id']));

        return $result;
    }

    /** @return array{0: array{id: string, type: string, sku: ?string, manage_stock: bool}, 1: array<string, mixed>} */
    private function resolve(string $productId, string $locationId, bool $entering): array
    {
        $product = $this->products->find($productId) ?? throw new NotFound('The product');
        (! in_array($product['type'], ['variable', 'grouped'], true) && $product['manage_stock'])
            || throw new InvalidMovement('This product does not keep stock of its own.', 'PRODUCT_DOES_NOT_TRACK_STOCK');
        $location = $this->locations->find($locationId) ?? throw new NotFound('The location');
        ! $entering || $location['is_active'] || throw new InvalidMovement('That location is inactive.', 'LOCATION_INACTIVE');

        return [$product, $location];
    }

    /**
     * Writes one row. Caller is inside a transaction. Locks (product, location) itself when the caller did not.
     *
     * @param  array<string, mixed>  $location
     */
    private function write(string $productId, array $location, string $type, BigDecimal $signed, Actor $actor, ?string $counterpart, ?string $key, ?int $expectedVersion, ?string $referenceType, ?string $referenceId, ?string $note): string
    {
        $level = $this->store->lockStock($productId, $location['id']);

        if ($key !== null && ($seen = $this->store->findByIdempotencyKey($key)) !== null) {
            ($seen['product_id'] === $productId && $seen['location_id'] === $location['id'] && $seen['type'] === $type)
                || throw new Conflict('That idempotency key was already used for a different movement.', 'IDEMPOTENCY_KEY_REUSED');

            return $seen['ulid'];
        }
        $expectedVersion === null || $expectedVersion === $level['version'] || throw new Conflict('Someone else changed this shelf while you were looking at it. Reload and try again.', 'STALE_VERSION');

        $balance = Quantities::of($level['quantity'])->plus($signed);
        $balance->isNegative() && throw new InsufficientStock($signed->abs()->__toString(), $level['quantity'], $this->products->find($productId)['sku'] ?? null);

        $ulid = strtolower((string) Str::ulid());
        $row = [
            'ulid' => $ulid, 'product_id' => $productId, 'warehouse_id' => $location['warehouse_id'], 'location_id' => $location['id'], 'counterpart_location_id' => $counterpart,
            'quantity' => (string) $signed, 'type' => $type, 'balance_after' => (string) $balance, 'reference_type' => $referenceType, 'reference_id' => $referenceId,
            'idempotency_key' => $key, 'created_by' => $actor->userId, 'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
        ];
        $previous = $this->store->lastHash($productId, $location['id']);
        $this->store->append([
            'ulid' => $ulid, 'product_id' => $productId, 'warehouse_id' => $location['warehouse_id'], 'location_id' => $location['id'], 'counterpart_location_id' => $counterpart,
            'quantity' => (string) $signed, 'type' => $type, 'balance_after' => (string) $balance, 'reference_type' => $referenceType, 'reference_id' => $referenceId,
            'note' => $note === null ? null : mb_substr($note, 0, 500), 'idempotency_key' => $key, 'created_by' => $actor->userId, 'ip_address' => $actor->client?->ip,
            'country_code' => $actor->client?->country, 'timezone' => $actor->client?->timezone, 'created_at' => $row['created_at'],
            'prev_hash' => $previous, 'row_hash' => $this->hasher->hash($previous, $row),
        ]);
        $this->store->saveStock($productId, $location['id'], (string) $balance, $level['version'] + 1);

        return $ulid;
    }
}
