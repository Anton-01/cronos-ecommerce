<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Each Kardex row carries a SHA-256 over its own content plus the hash of the previous row of the same product and warehouse.
 * Editing, inserting or dropping any past row breaks every hash after it, which inventory:reconcile detects.
 */
final class MovementHasher
{
    /** @param array{ulid: string, product_id: string, warehouse_id: int|string, quantity: string, type: string, unit_cost: ?string, balance_after: string, reference_type: ?string, reference_id: ?string, created_at: string} $row */
    public function hash(?string $previous, array $row): string
    {
        return hash('sha256', implode('|', [
            $previous ?? '', $row['ulid'], $row['product_id'], (string) $row['warehouse_id'], $this->decimal($row['quantity'], 3), $row['type'],
            $row['unit_cost'] === null ? '' : $this->decimal($row['unit_cost'], 4), $this->decimal($row['balance_after'], 3),
            $row['reference_type'] ?? '', $row['reference_id'] ?? '', $row['created_at'],
        ]));
    }

    private function decimal(string $value, int $scale): string
    {
        return (string) BigDecimal::of($value)->toScale($scale, RoundingMode::HalfUp);
    }
}
