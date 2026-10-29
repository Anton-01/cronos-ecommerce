<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Persistence;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Cronos\Inventory\Domain\Ports\InventoryQueries;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DatabaseInventoryQueries implements InventoryQueries
{
    public function stock(?string $search, ?int $warehouseId, bool $lowOnly, int $limit, int $offset): array
    {
        $query = $this->products()
            ->when($search !== null && trim($search) !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('p.sku', 'ilike', '%'.addcslashes(trim($search), '%_\\').'%')->orWhere('name.name', 'ilike', '%'.addcslashes(trim($search), '%_\\').'%')->orWhere('parent_name.name', 'ilike', '%'.addcslashes(trim($search), '%_\\').'%')))
            ->when($warehouseId !== null, fn (Builder $q) => $q->whereExists(fn (Builder $e) => $e->selectRaw('1')->from('stock_levels as f')->whereColumn('f.product_id', 'p.id')->where('f.warehouse_id', $warehouseId)));

        $total = (clone $query)->count();
        $rows = $query->orderByRaw('COALESCE(name.name, parent_name.name, p.sku)')->orderBy('p.id')->limit($limit)->offset($offset)
            ->selectRaw($this->columns())->get();

        $levels = $rows->isEmpty() ? collect() : DB::table('stock_levels as sl')->join('warehouses as w', 'w.id', '=', 'sl.warehouse_id')->whereIn('sl.product_id', $rows->pluck('id'))->whereNull('w.deleted_at')
            ->orderBy('w.code')->get(['sl.product_id', 'sl.warehouse_id', 'w.code as warehouse_code', 'sl.on_hand', 'sl.reserved', 'sl.available'])->groupBy('product_id');

        $items = $rows->map(fn ($r) => $this->stockRow($r) + ['warehouses' => ($levels[$r->id] ?? collect())->map(fn ($l) => [
            'warehouse_id' => (int) $l->warehouse_id, 'warehouse_code' => $l->warehouse_code, 'on_hand' => (string) $l->on_hand, 'reserved' => (string) $l->reserved, 'available' => (string) $l->available,
        ])->values()->all()]);

        return ['rows' => $items->filter(fn ($r) => ! $lowOnly || $r['low'])->values()->all(), 'total' => $total];
    }

    public function lowStock(?string $productId = null): array
    {
        return $this->products()->whereNotNull('p.low_stock_threshold')->when($productId, fn (Builder $q) => $q->where('p.id', $productId))
            ->selectRaw($this->columns())->orderByRaw('COALESCE(name.name, parent_name.name, p.sku)')->get()
            ->map(fn ($r) => $this->stockRow($r))->filter(fn ($r) => $r['low'])->values()->all();
    }

    public function movements(?string $productId, ?int $warehouseId, ?string $type, ?string $before, int $limit): array
    {
        return DB::table('inventory_movements as m')->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')->join('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('product_translations as name', fn ($j) => $j->on('name.product_id', '=', 'p.id')->where('name.locale', config('app.locale_default'))->whereNull('name.deleted_at'))
            ->leftJoin('product_translations as parent_name', fn ($j) => $j->on('parent_name.product_id', '=', 'p.parent_id')->where('parent_name.locale', config('app.locale_default'))->whereNull('parent_name.deleted_at'))
            ->leftJoin('users as u', 'u.id', '=', 'm.created_by')
            ->leftJoin('inventory_movements as fix', fn ($j) => $j->on('fix.reference_id', '=', 'm.ulid')->where('fix.reference_type', 'movement'))
            ->when($productId, fn ($q) => $q->where('m.product_id', $productId))->when($warehouseId, fn ($q) => $q->where('m.warehouse_id', $warehouseId))
            ->when($type, fn ($q) => $q->where('m.type', $type))->when($before, fn ($q) => $q->where('m.id', '<', (int) $before))
            ->orderByDesc('m.id')->limit($limit)
            ->get(['m.id', 'm.ulid', 'm.product_id', 'p.sku', DB::raw('COALESCE(name.name, parent_name.name) as product_name'), 'm.warehouse_id', 'w.code as warehouse_code', 'm.quantity', 'm.type', 'm.unit_cost', 'm.balance_after',
                'm.reference_type', 'm.reference_id', 'm.note', 'u.email as user_email', 'm.ip_address', 'm.country_code', 'm.timezone', 'm.created_at', 'fix.ulid as compensated_by'])
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'ulid' => $r->ulid, 'product_id' => $r->product_id, 'sku' => $r->sku, 'product_name' => $r->product_name, 'warehouse_id' => (int) $r->warehouse_id, 'warehouse_code' => $r->warehouse_code,
                'quantity' => (string) $r->quantity, 'type' => $r->type, 'unit_cost' => $r->unit_cost === null ? null : (string) $r->unit_cost, 'balance_after' => (string) $r->balance_after,
                'reference_type' => $r->reference_type, 'reference_id' => $r->reference_id, 'note' => $r->note, 'user_email' => $r->user_email, 'ip' => $r->ip_address, 'country' => $r->country_code,
                'timezone' => $r->timezone, 'created_at' => CarbonImmutable::parse($r->created_at)->utc(), 'compensated_by' => $r->compensated_by,
            ])->all();
    }

    public function reservations(?string $productId, int $limit): array
    {
        return DB::table('inventory_reservations as r')->join('warehouses as w', 'w.id', '=', 'r.warehouse_id')->join('products as p', 'p.id', '=', 'r.product_id')
            ->where('r.status', 'active')->when($productId, fn ($q) => $q->where('r.product_id', $productId))->orderBy('r.expires_at')->limit($limit)
            ->get(['r.id', 'r.product_id', 'p.sku', 'w.code as warehouse_code', 'r.quantity', 'r.holder_type', 'r.holder_id', 'r.expires_at'])
            ->map(fn ($r) => ['id' => $r->id, 'product_id' => $r->product_id, 'sku' => $r->sku, 'warehouse_code' => $r->warehouse_code, 'quantity' => (string) $r->quantity, 'holder_type' => $r->holder_type, 'holder_id' => $r->holder_id, 'expires_at' => CarbonImmutable::parse($r->expires_at)->utc()])->all();
    }

    /** Stock-tracking, buyable products (simple and variations) with their totals across active warehouses. */
    private function products(): Builder
    {
        return DB::table('products as p')
            ->leftJoin('product_translations as name', fn ($j) => $j->on('name.product_id', '=', 'p.id')->where('name.locale', config('app.locale_default'))->whereNull('name.deleted_at'))
            ->leftJoin('product_translations as parent_name', fn ($j) => $j->on('parent_name.product_id', '=', 'p.parent_id')->where('parent_name.locale', config('app.locale_default'))->whereNull('parent_name.deleted_at'))
            ->whereNull('p.deleted_at')->where('p.manage_stock', true)->whereIn('p.type', ['simple', 'variation']);
    }

    private function columns(): string
    {
        return 'p.id, p.sku, p.type, p.low_stock_threshold, COALESCE(name.name, parent_name.name) AS name,
            COALESCE((SELECT SUM(sl.on_hand) FROM stock_levels sl JOIN warehouses w ON w.id = sl.warehouse_id WHERE sl.product_id = p.id AND w.is_active AND w.deleted_at IS NULL), 0) AS on_hand,
            COALESCE((SELECT SUM(sl.reserved) FROM stock_levels sl JOIN warehouses w ON w.id = sl.warehouse_id WHERE sl.product_id = p.id AND w.is_active AND w.deleted_at IS NULL), 0) AS reserved';
    }

    /** @return array<string, mixed> */
    private function stockRow(object $r): array
    {
        $available = (string) BigDecimal::of((string) $r->on_hand)->minus((string) $r->reserved)->toScale(3);
        $threshold = $r->low_stock_threshold === null ? null : (string) $r->low_stock_threshold;

        return [
            'product_id' => $r->id, 'sku' => $r->sku, 'name' => $r->name, 'type' => $r->type, 'on_hand' => (string) $r->on_hand, 'reserved' => (string) $r->reserved, 'available' => $available,
            'low_stock_threshold' => $threshold, 'low' => $threshold !== null && BigDecimal::of($available)->isLessThanOrEqualTo($threshold),
        ];
    }
}
