<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Brick\Math\BigDecimal;
use Cronos\Inventory\Domain\Ports\LocationStore;
use Cronos\Inventory\Domain\Services\LocationMovementHasher;

/**
 * Proves the per-shelf numbers still agree with the location ledger and that the ledger was not touched.
 * `variance` (what the shelves hold versus what the warehouse books say) is informational: the shelves are the physical truth maintained by
 * staff, the books are what the shop sells; a difference is work to do ("pending put-away"), not corruption.
 */
final class LocationReconciler
{
    public function __construct(private readonly LocationStore $store, private readonly LocationMovementHasher $hasher) {}

    /** @return array{ok: bool, stock_mismatches: list<string>, broken_chains: list<string>, untracked_codes: list<string>, variance: list<string>} */
    public function check(): array
    {
        $s = $this->store->reconciliationSnapshot();
        $report = ['ok' => true, 'stock_mismatches' => [], 'broken_chains' => [], 'untracked_codes' => $s['untracked_codes'], 'variance' => []];

        foreach (array_unique([...array_keys($s['stock']), ...array_keys($s['ledger'])]) as $key) {
            BigDecimal::of($s['stock'][$key] ?? '0')->isEqualTo(BigDecimal::of($s['ledger'][$key] ?? '0'))
                || $report['stock_mismatches'][] = "{$key}: shelf {$s['stock'][$key]} vs ledger ".($s['ledger'][$key] ?? '0');
        }
        foreach (array_keys($s['ledger']) as $key) {
            [$productId, $locationId] = explode('|', $key);
            $previous = null;
            foreach ($this->store->chain($productId, $locationId) as $row) {
                if ($row['prev_hash'] !== $previous || $row['row_hash'] !== $this->hasher->hash($previous, $row)) {
                    $report['broken_chains'][] = "{$key}: movement {$row['ulid']}";
                    break;
                }
                $previous = $row['row_hash'];
            }
        }
        foreach (array_unique([...array_keys($s['locations']), ...array_keys($s['books'])]) as $key) {
            $shelves = BigDecimal::of($s['locations'][$key] ?? '0');
            $books = BigDecimal::of($s['books'][$key] ?? '0');
            $shelves->isEqualTo($books) || $report['variance'][] = "{$key}: shelves {$shelves} vs warehouse books {$books}";
        }
        $report['ok'] = $report['stock_mismatches'] === [] && $report['broken_chains'] === [] && $report['untracked_codes'] === [];

        return $report;
    }

    /** Checks one product+shelf chain. @return ?string the first movement that no longer verifies, null when the chain is intact */
    public function verify(string $productId, string $locationId): ?string
    {
        $previous = null;
        foreach ($this->store->chain($productId, $locationId) as $row) {
            if ($row['prev_hash'] !== $previous || $row['row_hash'] !== $this->hasher->hash($previous, $row)) {
                return $row['ulid'];
            }
            $previous = $row['row_hash'];
        }

        return null;
    }

    /** Rebuilds the per-shelf quantities from the ledger. Cannot (and does not try to) fix the ledger itself. */
    public function repairDerived(): int
    {
        return $this->store->rebuildDerived();
    }
}
