<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Same idea as the Kardex's hash, one chain per (product, location). The hash also covers WHO did it and the idempotency key: changing the
 * attribution of a movement after the fact breaks every hash from that row on, which `inventory:reconcile-locations` detects.
 */
final class LocationMovementHasher
{
    /** @param array{ulid: string, product_id: string, warehouse_id: int|string, location_id: string, counterpart_location_id: ?string, quantity: string, type: string, balance_after: string, reference_type: ?string, reference_id: ?string, idempotency_key: ?string, created_by: ?string, created_at: string} $row */
    public function hash(?string $previous, array $row): string
    {
        return hash('sha256', implode('|', [
            $previous ?? '', $row['ulid'], $row['product_id'], (string) $row['warehouse_id'], $row['location_id'], $row['counterpart_location_id'] ?? '',
            $this->decimal($row['quantity']), $row['type'], $this->decimal($row['balance_after']), $row['reference_type'] ?? '', $row['reference_id'] ?? '',
            $row['idempotency_key'] ?? '', $row['created_by'] ?? '', $row['created_at'],
        ]));
    }

    private function decimal(string $value): string
    {
        return (string) BigDecimal::of($value)->toScale(3, RoundingMode::HalfUp);
    }
}
