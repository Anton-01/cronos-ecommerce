<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Cronos\Inventory\Domain\Ports\LocationStore;
use Illuminate\Support\Facades\DB;

final class DatabaseLocationStore implements LocationStore
{
    public function atomically(callable $work): mixed
    {
        return DB::transaction($work);
    }

    public function lockStock(string $productId, string $locationId): array
    {
        DB::statement('INSERT INTO location_stock (product_id, location_id) VALUES (?, ?) ON CONFLICT DO NOTHING', [$productId, $locationId]);
        $row = DB::selectOne('SELECT quantity, version FROM location_stock WHERE product_id = ? AND location_id = ? FOR UPDATE', [$productId, $locationId]);

        return ['quantity' => (string) $row->quantity, 'version' => (int) $row->version];
    }

    public function saveStock(string $productId, string $locationId, string $quantity, int $version): void
    {
        DB::table('location_stock')->where(['product_id' => $productId, 'location_id' => $locationId])->update(['quantity' => $quantity, 'version' => $version, 'updated_at' => now()]);
    }

    public function lastHash(string $productId, string $locationId): ?string
    {
        return DB::table('location_movements')->where(['product_id' => $productId, 'location_id' => $locationId])->orderByDesc('id')->value('row_hash');
    }

    public function append(array $movement): void
    {
        $movement['created_at'] = CarbonImmutable::parse($movement['created_at'])->utc()->format('Y-m-d H:i:s.u').'+00';
        DB::table('location_movements')->insert($movement);
    }

    public function findMovement(string $ulid): ?array
    {
        return $this->one(DB::table('location_movements')->where('ulid', $ulid)->first());
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->one(DB::table('location_movements')->where('idempotency_key', $key)->first());
    }

    public function compensationOf(string $ulid): ?array
    {
        return $this->one(DB::table('location_movements')->where(['reference_type' => 'location_movement', 'reference_id' => $ulid])->first());
    }

    public function chain(string $productId, string $locationId): array
    {
        return DB::table('location_movements')->where(['product_id' => $productId, 'location_id' => $locationId])->orderBy('id')->get()->map(fn ($r) => $this->one($r))->all();
    }

    public function reconciliationSnapshot(): array
    {
        $map = fn ($rows, callable $key, string $col) => $rows->mapWithKeys(fn ($r) => [$key($r) => (string) $r->{$col}])->all();
        $pl = static fn ($r): string => $r->product_id.'|'.$r->location_id;
        $pw = static fn ($r): string => $r->product_id.'|'.$r->warehouse_id;

        return [
            'stock' => $map(DB::table('location_stock')->get(), $pl, 'quantity'),
            'ledger' => $map(DB::table('location_movements')->groupBy('product_id', 'location_id')->selectRaw('product_id, location_id, SUM(quantity) AS total')->get(), $pl, 'total'),
            'locations' => $map(DB::table('location_stock as ls')->join('warehouse_locations as l', 'l.id', '=', 'ls.location_id')->groupBy('ls.product_id', 'l.warehouse_id')->selectRaw('ls.product_id, l.warehouse_id, SUM(ls.quantity) AS total')->get(), $pw, 'total'),
            'books' => $map(DB::table('stock_levels')->get(), $pw, 'on_hand'),
            'untracked_codes' => DB::table('products')->whereNull('deleted_at')->whereNull('stock_code')->whereIn('type', ['simple', 'variation'])->pluck('id')->all(),
        ];
    }

    public function rebuildDerived(): int
    {
        $fixed = DB::affectingStatement('UPDATE location_stock ls SET quantity = COALESCE(m.total, 0), updated_at = now() FROM (SELECT ls2.product_id, ls2.location_id, (SELECT SUM(quantity) FROM location_movements lm WHERE lm.product_id = ls2.product_id AND lm.location_id = ls2.location_id) AS total FROM location_stock ls2) m WHERE ls.product_id = m.product_id AND ls.location_id = m.location_id AND ls.quantity IS DISTINCT FROM COALESCE(m.total, 0)');
        $fixed += DB::affectingStatement('INSERT INTO location_stock (product_id, location_id, quantity, version) SELECT product_id, location_id, SUM(quantity), COUNT(*) FROM location_movements GROUP BY product_id, location_id ON CONFLICT DO NOTHING');

        return $fixed;
    }

    /** @return array<string, mixed>|null */
    private function one(?object $r): ?array
    {
        return $r === null ? null : [
            'ulid' => $r->ulid, 'product_id' => $r->product_id, 'warehouse_id' => (int) $r->warehouse_id, 'location_id' => $r->location_id,
            'counterpart_location_id' => $r->counterpart_location_id, 'quantity' => (string) $r->quantity, 'type' => $r->type, 'balance_after' => (string) $r->balance_after,
            'reference_type' => $r->reference_type, 'reference_id' => $r->reference_id, 'idempotency_key' => $r->idempotency_key, 'created_by' => $r->created_by,
            'note' => $r->note, 'created_at' => CarbonImmutable::parse($r->created_at)->utc()->format('Y-m-d\TH:i:s.u\Z'), 'prev_hash' => $r->prev_hash, 'row_hash' => $r->row_hash,
        ];
    }
}
