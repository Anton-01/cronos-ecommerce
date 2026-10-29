<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Brick\Math\BigDecimal;
use Cronos\Inventory\Domain\Events\StockChanged;
use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Cronos\Inventory\Domain\Exceptions\NotFound;
use Cronos\Inventory\Domain\Ports\ProductStockInfo;
use Cronos\Inventory\Domain\Ports\StockStore;
use Cronos\Inventory\Domain\Ports\WarehouseRepository;
use Cronos\Inventory\Domain\Services\MovementHasher;
use Cronos\Inventory\Domain\Services\MovementRules;
use Cronos\Inventory\Domain\Services\Quantities;
use Cronos\Inventory\Domain\ValueObjects\MovementType;
use Cronos\Shared\Domain\DomainEventDispatcher;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;

/**
 * The only way stock changes: append a row to the Kardex and move the derived level, in one transaction with the level locked.
 * Nothing here edits or deletes a past movement.
 */
final class StockLedger
{
    public function __construct(
        private readonly StockStore $store,
        private readonly ProductStockInfo $products,
        private readonly WarehouseRepository $warehouses,
        private readonly MovementRules $rules,
        private readonly MovementHasher $hasher,
        private readonly DomainEventDispatcher $events,
    ) {}

    /**
     * @param  string|int|float|BigDecimal  $amount  always positive; the type (and $increase for adjustments) decides the direction
     * @param  ?string  $releaseReserved  amount of a reservation this movement consumes (reservation_consume only)
     * @return string ulid of the new Kardex row
     */
    public function record(
        string $productId,
        int $warehouseId,
        string $type,
        string|int|float|BigDecimal $amount,
        Actor $actor,
        ?bool $increase = null,
        ?string $unitCost = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $note = null,
        ?string $releaseReserved = null,
    ): string {
        $product = $this->products->find($productId) ?? throw new NotFound('The product');
        (! in_array($product['type'], ['variable', 'grouped'], true) && $product['manage_stock'])
            || throw new InvalidMovement('This product does not keep stock of its own (variable/grouped products, or stock management is off).', 'PRODUCT_DOES_NOT_TRACK_STOCK');
        $warehouse = $this->warehouses->find($warehouseId) ?? throw new NotFound('The warehouse');
        $warehouse['is_active'] || throw new InvalidMovement('That warehouse is not active.', 'WAREHOUSE_INACTIVE');
        ($unitCost === null || (is_numeric($unitCost) && (float) $unitCost >= 0)) || throw new InvalidMovement('The unit cost must be zero or more.', 'INVALID_COST');

        $quantity = $this->rules->signed($type, Quantities::positive($amount), $increase);

        $ulid = $this->store->atomically(function () use ($productId, $warehouseId, $type, $quantity, $actor, $unitCost, $referenceType, $referenceId, $note, $releaseReserved, $product): string {
            $level = $this->store->lockLevel($productId, $warehouseId);
            $onHand = Quantities::of($level['on_hand']);
            $reserved = Quantities::of($level['reserved']);
            $release = $releaseReserved === null ? Quantities::zero() : Quantities::positive($releaseReserved);

            $quantity->isNegative() && $this->rules->assertCanTake($onHand, $reserved, $quantity->abs(), $product['allow_backorder'], $type === MovementType::RESERVATION_CONSUME, $product['sku']);
            $reserved->isGreaterThanOrEqualTo($release) || throw new InvalidMovement('More reservation released than was reserved.', 'RESERVATION_MISMATCH');

            $newOnHand = $onHand->plus($quantity);
            $ulid = strtolower((string) Str::ulid());
            $createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $row = [
                'ulid' => $ulid, 'product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => (string) $quantity, 'type' => $type,
                'unit_cost' => $unitCost, 'balance_after' => (string) $newOnHand, 'reference_type' => $referenceType, 'reference_id' => $referenceId,
                'created_at' => $createdAt->format('Y-m-d\TH:i:s.u\Z'),
            ];
            $previous = $this->store->lastHash($productId, $warehouseId);

            $this->store->append($row + [
                'note' => $note === null ? null : mb_substr($note, 0, 500), 'created_by' => $actor->userId, 'ip_address' => $actor->client?->ip,
                'country_code' => $actor->client?->country, 'timezone' => $actor->client?->timezone,
                'prev_hash' => $previous, 'row_hash' => $this->hasher->hash($previous, $row),
            ]);
            $this->store->saveLevel($productId, $warehouseId, (string) $newOnHand, (string) $reserved->minus($release));
            $this->store->refreshProductStock($productId);

            return $ulid;
        });

        $this->events->dispatch(new StockChanged($productId));

        return $ulid;
    }
}
