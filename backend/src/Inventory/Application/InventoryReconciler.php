<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Brick\Math\BigDecimal;
use Cronos\Inventory\Domain\Ports\StockStore;
use Cronos\Inventory\Domain\Services\MovementHasher;

/**
 * Proves the derived numbers (levels, product stock) still agree with the Kardex, and that the Kardex itself was not touched.
 * Differences are reported, never silently corrected: the Kardex is the truth, so repair only rebuilds what is derived from it.
 */
final class InventoryReconciler
{
    public function __construct(private readonly StockStore $store, private readonly MovementHasher $hasher) {}

    /** @return array{ok: bool, level_mismatches: list<string>, reserved_mismatches: list<string>, product_mismatches: list<string>, broken_chains: list<string>} */
    public function check(): array
    {
        $snapshot = $this->store->reconciliationSnapshot();
        $report = ['ok' => true, 'level_mismatches' => [], 'reserved_mismatches' => [], 'product_mismatches' => [], 'broken_chains' => []];

        foreach ($snapshot['levels'] + array_fill_keys(array_keys($snapshot['ledger']), ['on_hand' => '0', 'reserved' => '0']) as $key => $level) {
            $ledger = BigDecimal::of($snapshot['ledger'][$key] ?? '0');
            BigDecimal::of($level['on_hand'])->isEqualTo($ledger) || $report['level_mismatches'][] = "{$key}: level {$level['on_hand']} vs Kardex {$ledger}";
            BigDecimal::of($level['reserved'])->isEqualTo(BigDecimal::of($snapshot['reservations'][$key] ?? '0')) || $report['reserved_mismatches'][] = "{$key}: level reserved {$level['reserved']} vs active reservations ".($snapshot['reservations'][$key] ?? '0');
        }
        foreach ($snapshot['products'] as $productId => $stored) {
            BigDecimal::of($stored)->isEqualTo(BigDecimal::of($snapshot['expected_products'][$productId] ?? '0')) || $report['product_mismatches'][] = "{$productId}: products.stock_quantity {$stored} vs expected ".($snapshot['expected_products'][$productId] ?? '0');
        }

        foreach (array_keys($snapshot['ledger']) as $key) {
            [$productId, $warehouseId] = explode('|', $key);
            $previous = null;
            foreach ($this->store->chain($productId, (int) $warehouseId) as $row) {
                if ($row['prev_hash'] !== $previous || $row['row_hash'] !== $this->hasher->hash($previous, $row)) {
                    $report['broken_chains'][] = "{$key}: movement {$row['ulid']}";
                    break;
                }
                $previous = $row['row_hash'];
            }
        }

        $report['ok'] = $report['level_mismatches'] === [] && $report['reserved_mismatches'] === [] && $report['product_mismatches'] === [] && $report['broken_chains'] === [];

        return $report;
    }

    /** Rebuilds levels and product stock from the Kardex and the live reservations. Does not touch (and cannot fix) the Kardex. */
    public function repairDerived(): int
    {
        return $this->store->rebuildDerived();
    }
}
