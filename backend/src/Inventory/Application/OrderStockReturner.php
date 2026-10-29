<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Brick\Math\BigDecimal;
use Cronos\Inventory\Domain\Ports\InventoryQueries;
use Cronos\Inventory\Domain\ValueObjects\MovementType;

/**
 * Puts back on the shelf what a cancelled order had taken: one RETURN movement per product and warehouse, at the cost the Kardex already
 * knows. Idempotent: whatever was already returned is subtracted, so running it twice returns nothing the second time.
 */
final class OrderStockReturner
{
    public function __construct(private readonly InventoryQueries $queries, private readonly StockLedger $ledger) {}

    /** @return list<string> movement ulids */
    public function returnOrder(string $orderId, Actor $actor, string $note = 'Order cancelled'): array
    {
        $ulids = [];
        foreach ($this->queries->soldForOrder($orderId) as $row) {
            $pending = BigDecimal::of($row['quantity'])->minus($row['returned']);
            if ($pending->isLessThanOrEqualTo(0)) {
                continue;
            }
            $ulids[] = $this->ledger->record($row['product_id'], $row['warehouse_id'], MovementType::RETURN, $pending, $actor, referenceType: 'order_return', referenceId: $orderId, note: $note);
        }

        return $ulids;
    }
}
