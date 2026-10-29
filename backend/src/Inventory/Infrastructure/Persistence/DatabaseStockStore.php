<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Cronos\Inventory\Domain\Ports\StockStore;
use Illuminate\Support\Facades\DB;

final class DatabaseStockStore implements StockStore
{
    public function atomically(callable $work): mixed
    {
        return DB::transaction($work);
    }

    public function lockLevel(string $productId, int $warehouseId): array
    {
        DB::statement('INSERT INTO stock_levels (product_id, warehouse_id) VALUES (?, ?) ON CONFLICT DO NOTHING', [$productId, $warehouseId]);
        $row = DB::selectOne('SELECT on_hand, reserved FROM stock_levels WHERE product_id = ? AND warehouse_id = ? FOR UPDATE', [$productId, $warehouseId]);

        return ['on_hand' => (string) $row->on_hand, 'reserved' => (string) $row->reserved];
    }

    public function saveLevel(string $productId, int $warehouseId, string $onHand, string $reserved): void
    {
        DB::table('stock_levels')->where(['product_id' => $productId, 'warehouse_id' => $warehouseId])->update(['on_hand' => $onHand, 'reserved' => $reserved, 'updated_at' => now()]);
    }

    public function lastHash(string $productId, int $warehouseId): ?string
    {
        return DB::table('inventory_movements')->where(['product_id' => $productId, 'warehouse_id' => $warehouseId])->orderByDesc('id')->value('row_hash');
    }

    public function append(array $movement): void
    {
        $movement['created_at'] = CarbonImmutable::parse($movement['created_at'])->utc()->format('Y-m-d H:i:s.u').'+00';
        DB::table('inventory_movements')->insert($movement);
    }

    public function refreshProductStock(string $productId): string
    {
        // Variations and simple products keep their own stock; the available total of active warehouses, never negative (oversold backorders show as 0).
        DB::statement(
            'UPDATE products SET stock_quantity = LEAST(GREATEST(COALESCE((SELECT SUM(sl.available) FROM stock_levels sl JOIN warehouses w ON w.id = sl.warehouse_id WHERE sl.product_id = products.id AND w.is_active AND w.deleted_at IS NULL), 0), 0), 999999999) WHERE id = ?',
            [$productId],
        );

        return (string) DB::table('products')->where('id', $productId)->value('stock_quantity');
    }

    public function findMovement(string $ulid): ?array
    {
        $row = DB::table('inventory_movements')->where('ulid', $ulid)->first();

        return $row === null ? null : $this->movement($row);
    }

    public function compensationOf(string $ulid): ?array
    {
        $row = DB::table('inventory_movements')->where(['reference_type' => 'movement', 'reference_id' => $ulid])->first();

        return $row === null ? null : $this->movement($row);
    }

    public function level(string $productId, int $warehouseId): ?array
    {
        $row = DB::table('stock_levels')->where(['product_id' => $productId, 'warehouse_id' => $warehouseId])->first();

        return $row === null ? null : ['on_hand' => (string) $row->on_hand, 'reserved' => (string) $row->reserved, 'available' => (string) $row->available];
    }

    public function reconciliationSnapshot(): array
    {
        $key = static fn ($r): string => $r->product_id.'|'.$r->warehouse_id;
        $ledger = DB::table('inventory_movements')->groupBy('product_id', 'warehouse_id')->selectRaw('product_id, warehouse_id, SUM(quantity) AS total')->get()->mapWithKeys(fn ($r) => [$key($r) => (string) $r->total])->all();
        $levels = DB::table('stock_levels')->get()->mapWithKeys(fn ($r) => [$key($r) => ['on_hand' => (string) $r->on_hand, 'reserved' => (string) $r->reserved]])->all();
        $reservations = DB::table('inventory_reservations')->where('status', 'active')->groupBy('product_id', 'warehouse_id')->selectRaw('product_id, warehouse_id, SUM(quantity) AS total')->get()->mapWithKeys(fn ($r) => [$key($r) => (string) $r->total])->all();

        $tracked = array_unique(array_merge(array_map(fn ($k) => explode('|', $k)[0], array_keys($levels)), array_map(fn ($k) => explode('|', $k)[0], array_keys($ledger))));
        $expected = DB::table('stock_levels as sl')->join('warehouses as w', 'w.id', '=', 'sl.warehouse_id')->where('w.is_active', true)->whereNull('w.deleted_at')
            ->groupBy('sl.product_id')->selectRaw('sl.product_id, LEAST(GREATEST(SUM(sl.available), 0), 999999999) AS total')->get()->mapWithKeys(fn ($r) => [$r->product_id => (string) $r->total])->all();
        $products = DB::table('products')->whereIn('id', $tracked)->pluck('stock_quantity', 'id')->map(fn ($v) => (string) $v)->all();

        return ['ledger' => $ledger, 'levels' => $levels, 'reservations' => $reservations, 'products' => $products, 'expected_products' => $expected];
    }

    public function chain(string $productId, int $warehouseId): array
    {
        return DB::table('inventory_movements')->where(['product_id' => $productId, 'warehouse_id' => $warehouseId])->orderBy('id')->get()
            ->map(fn ($r) => $this->movement($r) + ['prev_hash' => $r->prev_hash, 'row_hash' => $r->row_hash])->all();
    }

    public function rebuildDerived(): int
    {
        return DB::transaction(function (): int {
            $fixed = 0;
            DB::statement('LOCK TABLE stock_levels IN SHARE ROW EXCLUSIVE MODE');

            $fixed += DB::affectingStatement(
                "INSERT INTO stock_levels (product_id, warehouse_id, on_hand, reserved, updated_at)
                 SELECT m.product_id, m.warehouse_id, SUM(m.quantity), COALESCE((SELECT SUM(r.quantity) FROM inventory_reservations r WHERE r.status = 'active' AND r.product_id = m.product_id AND r.warehouse_id = m.warehouse_id), 0), now()
                 FROM inventory_movements m GROUP BY m.product_id, m.warehouse_id
                 ON CONFLICT (product_id, warehouse_id) DO UPDATE SET on_hand = EXCLUDED.on_hand, reserved = EXCLUDED.reserved, updated_at = now()
                 WHERE stock_levels.on_hand IS DISTINCT FROM EXCLUDED.on_hand OR stock_levels.reserved IS DISTINCT FROM EXCLUDED.reserved",
            );
            // Levels with no movement at all (stray rows) are zeroed so the derived data cannot invent stock.
            $fixed += DB::affectingStatement("UPDATE stock_levels sl SET on_hand = 0, reserved = COALESCE((SELECT SUM(r.quantity) FROM inventory_reservations r WHERE r.status = 'active' AND r.product_id = sl.product_id AND r.warehouse_id = sl.warehouse_id), 0), updated_at = now() WHERE NOT EXISTS (SELECT 1 FROM inventory_movements m WHERE m.product_id = sl.product_id AND m.warehouse_id = sl.warehouse_id) AND (sl.on_hand <> 0)");
            $fixed += DB::affectingStatement(
                'UPDATE products p SET stock_quantity = t.total FROM (
                    SELECT p2.id, LEAST(GREATEST(COALESCE((SELECT SUM(sl.available) FROM stock_levels sl JOIN warehouses w ON w.id = sl.warehouse_id WHERE sl.product_id = p2.id AND w.is_active AND w.deleted_at IS NULL), 0), 0), 999999999) AS total
                    FROM products p2 WHERE EXISTS (SELECT 1 FROM stock_levels s WHERE s.product_id = p2.id)
                 ) t WHERE p.id = t.id AND p.stock_quantity IS DISTINCT FROM t.total',
            );

            return $fixed;
        });
    }

    /** @return array<string, mixed> */
    private function movement(object $r): array
    {
        return [
            'ulid' => $r->ulid, 'product_id' => $r->product_id, 'warehouse_id' => (int) $r->warehouse_id, 'quantity' => (string) $r->quantity, 'type' => $r->type,
            'unit_cost' => $r->unit_cost === null ? null : (string) $r->unit_cost, 'balance_after' => (string) $r->balance_after, 'reference_type' => $r->reference_type,
            'reference_id' => $r->reference_id, 'created_at' => CarbonImmutable::parse($r->created_at)->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }
}
