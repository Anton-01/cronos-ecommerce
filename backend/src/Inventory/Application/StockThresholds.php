<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Cronos\Inventory\Domain\Ports\AlertStore;
use Cronos\Inventory\Domain\Services\Quantities;

/** Minimum and critical level per product, in a whole warehouse or on one shelf. Every change is kept. */
final class StockThresholds
{
    public function __construct(private readonly AlertStore $store, private readonly StockAlertWatcher $watcher) {}

    /** @param ?string $minimum null removes the threshold */
    public function set(string $productId, int $warehouseId, ?string $locationId, ?string $minimum, ?string $critical, ?string $userId): void
    {
        $min = $minimum === null ? null : Quantities::of($minimum);
        $crit = $critical === null || $critical === '' ? null : Quantities::of($critical);
        ($min === null || ! $min->isNegative()) || throw new InvalidMovement('The minimum cannot be negative.', 'INVALID_THRESHOLD');
        ($crit === null || ($min !== null && ! $crit->isNegative() && $crit->isLessThanOrEqualTo($min))) || throw new InvalidMovement('The critical level must be between 0 and the minimum.', 'INVALID_THRESHOLD');

        $this->store->saveThreshold($productId, $warehouseId, $locationId, $min === null ? null : (string) $min, $crit === null ? null : (string) $crit, $userId);
        $this->watcher->evaluate($productId, $warehouseId, $locationId); // the new rule may already apply
    }

    /** @return list<array<string, mixed>> */
    public function list(?int $warehouseId = null): array
    {
        return $this->store->thresholds($warehouseId);
    }
}
