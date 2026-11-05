<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Cronos\Inventory\Domain\Ports\AlertStore;
use Cronos\Inventory\Domain\Ports\WarehouseQueries;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DatabaseWarehouseQueries implements WarehouseQueries
{
    public function __construct(private readonly AlertStore $alerts) {}

    /** Products with their display name (a variation without its own name borrows the parent's). */
    private function products(): Builder
    {
        $locale = config('app.locale_default');

        return DB::table('products as p')
            ->leftJoin('product_translations as n', fn ($j) => $j->on('n.product_id', '=', 'p.id')->where('n.locale', $locale)->whereNull('n.deleted_at'))
            ->leftJoin('product_translations as pn', fn ($j) => $j->on('pn.product_id', '=', 'p.parent_id')->where('pn.locale', $locale)->whereNull('pn.deleted_at'));
    }

    private const PRODUCT_COLUMNS = ['p.id', 'p.sku', 'p.type', 'p.status', 'p.stock_code', 'p.sell_unit', 'p.deleted_at', 'p.low_stock_threshold'];

    public function locations(int $warehouseId, bool $includeInactive): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT l.id, l.parent_id, l.code, l.name, l.type, l.depth, l.sort_order, l.capacity, l.is_pickable, l.is_active, l.is_system, l.version,
                   COALESCE(s.products, 0) AS products, COALESCE(s.units, 0) AS units
            FROM warehouse_locations l
            LEFT JOIN LATERAL (
                SELECT COUNT(DISTINCT ls.product_id) FILTER (WHERE ls.quantity > 0) AS products, COALESCE(SUM(ls.quantity), 0) AS units
                FROM warehouse_locations d JOIN location_stock ls ON ls.location_id = d.id WHERE d.path LIKE l.path || '%'
            ) s ON TRUE
            WHERE l.warehouse_id = ? AND (? OR l.is_active)
            ORDER BY l.depth, l.sort_order, l.code
        SQL, [$warehouseId, $includeInactive ? 'true' : 'false']);

        return array_map(fn ($r) => [
            'id' => $r->id, 'parent_id' => $r->parent_id, 'code' => $r->code, 'name' => $r->name, 'type' => $r->type, 'depth' => (int) $r->depth, 'capacity' => $r->capacity === null ? null : (string) $r->capacity,
            'is_pickable' => (bool) $r->is_pickable, 'is_active' => (bool) $r->is_active, 'is_system' => (bool) $r->is_system, 'version' => (int) $r->version,
            'products' => (int) $r->products, 'units' => (string) $r->units, 'warehouse_id' => $warehouseId,
        ], $rows);
    }

    public function locationByCode(string $code, ?int $warehouseId): ?array
    {
        $r = DB::table('warehouse_locations')->where('code', strtoupper(trim($code)))->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))->first(['id', 'warehouse_id', 'code', 'name', 'type', 'is_active']);

        return $r === null ? null : ['id' => $r->id, 'warehouse_id' => (int) $r->warehouse_id, 'code' => $r->code, 'name' => $r->name, 'type' => $r->type, 'is_active' => (bool) $r->is_active];
    }

    public function locationContents(string $locationId): array
    {
        return $this->products()->join('location_stock as ls', 'ls.product_id', '=', 'p.id')->where('ls.location_id', $locationId)->where('ls.quantity', '>', 0)->orderBy('p.sku')
            ->get(['p.id', 'p.sku', 'p.stock_code', 'ls.quantity', 'ls.version', DB::raw('COALESCE(n.name, pn.name) AS name')])
            ->map(fn ($r) => ['product_id' => $r->id, 'sku' => $r->sku, 'stock_code' => $r->stock_code, 'name' => $r->name, 'quantity' => (string) $r->quantity, 'version' => (int) $r->version])->all();
    }

    public function scan(string $stockCode): ?array
    {
        $p = $this->products()->where('p.stock_code', $stockCode)->first([...self::PRODUCT_COLUMNS, DB::raw('COALESCE(n.name, pn.name) AS name'), 'p.parent_id']);
        if ($p === null) {
            return null; // a code that was issued but whose product row is gone still resolves nowhere; the screen says "removed"
        }
        $places = DB::table('location_stock as ls')->join('warehouse_locations as l', 'l.id', '=', 'ls.location_id')->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->where('ls.product_id', $p->id)->orderByDesc('ls.quantity')
            ->get(['l.id', 'l.code', 'l.name', 'l.is_active', 'l.is_pickable', 'l.warehouse_id', 'w.code as warehouse_code', 'ls.quantity', 'ls.version'])
            ->map(fn ($r) => ['location_id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'is_active' => (bool) $r->is_active, 'is_pickable' => (bool) $r->is_pickable, 'warehouse_id' => (int) $r->warehouse_id, 'warehouse_code' => $r->warehouse_code, 'quantity' => (string) $r->quantity, 'version' => (int) $r->version])->all();
        $books = DB::table('stock_levels as sl')->join('warehouses as w', 'w.id', '=', 'sl.warehouse_id')->where('sl.product_id', $p->id)
            ->get(['sl.warehouse_id', 'w.code', 'sl.on_hand', 'sl.reserved', 'sl.available'])
            ->map(fn ($r) => ['warehouse_id' => (int) $r->warehouse_id, 'warehouse_code' => $r->code, 'on_hand' => (string) $r->on_hand, 'reserved' => (string) $r->reserved, 'available' => (string) $r->available])->all();

        return [
            'product_id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'type' => $p->type, 'status' => $p->status, 'removed' => $p->deleted_at !== null, 'stock_code' => $p->stock_code,
            'unit' => $p->sell_unit, 'places' => $places, 'books' => $books,
            'thresholds' => array_values(array_filter(array_map(fn (array $b) => ($t = $this->alerts->threshold($p->id, $b['warehouse_id'], null)) === null ? null : ['warehouse_id' => $b['warehouse_id']] + $t, $books))),
            'recent' => $this->trail(['productId' => $p->id], 10),
        ];
    }

    public function searchProducts(string $search, int $limit): array
    {
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($search))).'%';

        return $this->products()->whereNull('p.deleted_at')->where('p.manage_stock', true)->whereIn('p.type', ['simple', 'variation'])
            ->where(fn ($q) => $q->whereRaw('LOWER(p.sku) LIKE ?', [$term])->orWhereRaw('LOWER(COALESCE(n.name, pn.name)) LIKE ?', [$term])->orWhereRaw('LOWER(p.stock_code) LIKE ?', [$term]))
            ->orderBy('p.sku')->limit($limit)->get(['p.id', 'p.sku', 'p.stock_code', 'p.status', DB::raw('COALESCE(n.name, pn.name) AS name')])
            ->map(fn ($r) => ['product_id' => $r->id, 'sku' => $r->sku, 'name' => $r->name, 'stock_code' => $r->stock_code, 'status' => $r->status])->all();
    }

    public function trail(array $f, int $limit): array
    {
        $locale = config('app.locale_default');

        return DB::table('location_movements as m')->join('warehouse_locations as l', 'l.id', '=', 'm.location_id')->join('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('warehouse_locations as o', 'o.id', '=', 'm.counterpart_location_id')
            ->leftJoin('product_translations as n', fn ($j) => $j->on('n.product_id', '=', 'p.id')->where('n.locale', $locale)->whereNull('n.deleted_at'))
            ->leftJoin('product_translations as pn', fn ($j) => $j->on('pn.product_id', '=', 'p.parent_id')->where('pn.locale', $locale)->whereNull('pn.deleted_at'))
            ->leftJoin('users as u', 'u.id', '=', 'm.created_by')->leftJoin('user_profiles as up', 'up.user_id', '=', 'u.id')
            ->leftJoin('location_movements as fix', fn ($j) => $j->on('fix.reference_id', '=', 'm.ulid')->where('fix.reference_type', 'location_movement'))
            ->when($f['productId'] ?? null, fn ($q, $v) => $q->where('m.product_id', $v))
            ->when($f['locationId'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('m.location_id', $v)->orWhere('m.counterpart_location_id', $v)))
            ->when($f['warehouseId'] ?? null, fn ($q, $v) => $q->where('m.warehouse_id', $v))
            ->when($f['userId'] ?? null, fn ($q, $v) => $q->where('m.created_by', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('m.type', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('m.created_at', '>=', $v))->when($f['to'] ?? null, fn ($q, $v) => $q->where('m.created_at', '<', $v))
            ->when($f['before'] ?? null, fn ($q, $v) => $q->where('m.id', '<', $v))
            ->orderByDesc('m.id')->limit($limit)
            ->get(['m.id', 'm.ulid', 'm.product_id', 'p.sku', DB::raw('COALESCE(n.name, pn.name) AS product_name'), 'm.warehouse_id', 'm.location_id', 'l.code as location_code', 'l.name as location_name',
                'o.code as counterpart_code', 'm.quantity', 'm.type', 'm.balance_after', 'm.reference_type', 'm.reference_id', 'm.note', 'm.created_by', 'u.email as user_email',
                DB::raw("NULLIF(TRIM(CONCAT(up.first_name, ' ', up.last_name)), '') AS user_name"), 'm.ip_address', 'm.country_code', 'm.timezone', 'm.created_at', 'fix.ulid as compensated_by'])
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'ulid' => $r->ulid, 'product_id' => $r->product_id, 'sku' => $r->sku, 'product_name' => $r->product_name, 'warehouse_id' => (int) $r->warehouse_id,
                'location_id' => $r->location_id, 'location_code' => $r->location_code, 'location_name' => $r->location_name, 'counterpart_code' => $r->counterpart_code,
                'quantity' => (string) $r->quantity, 'balance_after' => (string) $r->balance_after, 'balance_before' => (string) (round((float) $r->balance_after - (float) $r->quantity, 3)),
                'type' => $r->type, 'reference_type' => $r->reference_type, 'reference_id' => $r->reference_id, 'note' => $r->note, 'user_id' => $r->created_by, 'user_email' => $r->user_email, 'user_name' => $r->user_name,
                'ip' => $r->ip_address, 'country' => $r->country_code, 'timezone' => $r->timezone, 'created_at' => CarbonImmutable::parse($r->created_at)->utc(), 'compensated_by' => $r->compensated_by,
            ])->all();
    }

    public function describePickLines(array $lines): array
    {
        if ($lines === []) {
            return [];
        }
        $products = $this->products()->whereIn('p.id', array_unique(array_column($lines, 'product_id')))->get(['p.id', 'p.sku', 'p.stock_code', DB::raw('COALESCE(n.name, pn.name) AS name')])->keyBy('id');
        $orders = DB::table('orders')->whereIn('checkout_id', array_unique(array_column($lines, 'order_id')))->pluck('order_number', 'checkout_id');
        $shelves = DB::table('location_stock as ls')->join('warehouse_locations as l', 'l.id', '=', 'ls.location_id')->whereIn('ls.product_id', array_unique(array_column($lines, 'product_id')))
            ->where('ls.quantity', '>', 0)->where('l.is_active', true)->orderByDesc('l.is_pickable')->orderByDesc('ls.quantity')->get(['ls.product_id', 'l.warehouse_id', 'l.id', 'l.code', 'l.is_pickable', 'ls.quantity', 'ls.version'])->groupBy('product_id');

        return array_map(fn (array $l) => $l + [
            'sku' => $products[$l['product_id']]->sku ?? null, 'name' => $products[$l['product_id']]->name ?? null, 'stock_code' => $products[$l['product_id']]->stock_code ?? null,
            'order_number' => $orders[$l['order_id']] ?? null,
            'shelves' => $shelves->get($l['product_id'], collect())->where('warehouse_id', $l['warehouse_id'])->map(fn ($s) => ['location_id' => $s->id, 'code' => $s->code, 'is_pickable' => (bool) $s->is_pickable, 'quantity' => (string) $s->quantity, 'version' => (int) $s->version])->values()->all(),
        ], $lines);
    }

    public function countSessions(?int $warehouseId, ?string $status, int $limit): array
    {
        return DB::table('count_sessions as s')->join('warehouses as w', 'w.id', '=', 's.warehouse_id')->leftJoin('warehouse_locations as l', 'l.id', '=', 's.scope_location_id')
            ->when($warehouseId, fn ($q) => $q->where('s.warehouse_id', $warehouseId))->when($status, fn ($q) => $q->where('s.status', $status))
            ->orderByDesc('s.created_at')->limit($limit)
            ->get(['s.id', 's.name', 's.status', 's.warehouse_id', 'w.code as warehouse_code', 'l.code as scope_code', 's.created_by', 's.created_at', 's.closed_at',
                DB::raw('(SELECT COUNT(*) FROM count_entries e WHERE e.session_id = s.id) AS entries'), DB::raw("(SELECT COUNT(*) FROM count_entries e WHERE e.session_id = s.id AND e.status = 'in_review') AS in_review")])
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'status' => $r->status, 'warehouse_id' => (int) $r->warehouse_id, 'warehouse_code' => $r->warehouse_code, 'scope_code' => $r->scope_code,
                'created_by' => $r->created_by, 'created_at' => CarbonImmutable::parse($r->created_at)->utc(), 'closed_at' => $r->closed_at === null ? null : CarbonImmutable::parse($r->closed_at)->utc(),
                'entries' => (int) $r->entries, 'in_review' => (int) $r->in_review])->all();
    }

    public function countEntries(string $sessionId, ?string $status, int $limit): array
    {
        return $this->products()->join('count_entries as e', 'e.product_id', '=', 'p.id')->join('warehouse_locations as l', 'l.id', '=', 'e.location_id')
            ->where('e.session_id', $sessionId)->when($status, fn ($q) => $q->where('e.status', $status))->orderBy('e.counted_at')->limit($limit)
            ->get(['e.id', 'e.product_id', 'p.sku', DB::raw('COALESCE(n.name, pn.name) AS name'), 'e.location_id', 'l.code as location_code', 'e.counted', 'e.system_at_start', 'e.status', 'e.counted_by', 'e.counted_at', 'e.note', 'e.reviewed_by', 'e.review_note', 'e.movement_ulid'])
            ->map(fn ($r) => ['id' => $r->id, 'product_id' => $r->product_id, 'sku' => $r->sku, 'name' => $r->name, 'location_id' => $r->location_id, 'location_code' => $r->location_code, 'counted' => (string) $r->counted,
                'system_at_start' => (string) $r->system_at_start, 'status' => $r->status, 'counted_by' => $r->counted_by, 'counted_at' => CarbonImmutable::parse($r->counted_at)->utc(), 'note' => $r->note,
                'reviewed_by' => $r->reviewed_by, 'review_note' => $r->review_note, 'movement_ulid' => $r->movement_ulid])->all();
    }

    public function adjustmentRequests(?string $status, ?int $warehouseId, int $limit): array
    {
        return $this->products()->join('stock_adjustment_requests as r', 'r.product_id', '=', 'p.id')->join('warehouse_locations as l', 'l.id', '=', 'r.location_id')
            ->when($status, fn ($q) => $q->where('r.status', $status))->when($warehouseId, fn ($q) => $q->where('r.warehouse_id', $warehouseId))->orderByDesc('r.requested_at')->limit($limit)
            ->get(['r.id', 'r.product_id', 'p.sku', DB::raw('COALESCE(n.name, pn.name) AS name'), 'r.warehouse_id', 'r.location_id', 'l.code as location_code', 'r.seen_quantity', 'r.target_quantity', 'r.delta', 'r.reason', 'r.status',
                'r.requested_by', 'r.requested_at', 'r.decided_by', 'r.decided_at', 'r.decision_note', 'r.movement_ulid'])
            ->map(fn ($r) => ['id' => $r->id, 'product_id' => $r->product_id, 'sku' => $r->sku, 'name' => $r->name, 'warehouse_id' => (int) $r->warehouse_id, 'location_id' => $r->location_id, 'location_code' => $r->location_code,
                'seen_quantity' => (string) $r->seen_quantity, 'target_quantity' => (string) $r->target_quantity, 'delta' => (string) $r->delta, 'reason' => $r->reason, 'status' => $r->status,
                'requested_by' => $r->requested_by, 'requested_at' => CarbonImmutable::parse($r->requested_at)->utc(), 'decided_by' => $r->decided_by,
                'decided_at' => $r->decided_at === null ? null : CarbonImmutable::parse($r->decided_at)->utc(), 'decision_note' => $r->decision_note, 'movement_ulid' => $r->movement_ulid])->all();
    }

    public function thresholds(?int $warehouseId, int $limit): array
    {
        $rows = $this->alerts->thresholds($warehouseId, $limit);
        $ids = array_values(array_unique(array_column($rows, 'product_id')));
        $names = $ids === [] ? collect() : $this->products()->whereIn('p.id', $ids)->get(['p.id', 'p.sku', DB::raw('COALESCE(n.name, pn.name) AS name')])->keyBy('id');
        $codes = DB::table('warehouse_locations')->whereIn('id', array_filter(array_column($rows, 'location_id')))->pluck('code', 'id');

        return array_map(fn (array $r) => [
            'product_id' => $r['product_id'], 'sku' => $names[$r['product_id']]->sku ?? null, 'name' => $names[$r['product_id']]->name ?? null, 'warehouse_id' => (int) $r['warehouse_id'],
            'location_id' => $r['location_id'], 'location_code' => $r['location_id'] === null ? null : ($codes[$r['location_id']] ?? null), 'minimum' => (string) $r['minimum'],
            'critical' => $r['critical'] === null ? null : (string) $r['critical'], 'changed_by' => $r['changed_by'], 'changed_at' => CarbonImmutable::parse($r['changed_at'])->utc(),
        ], $rows);
    }

    public function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('users as u')->leftJoin('user_profiles as up', 'up.user_id', '=', 'u.id')->whereIn('u.id', $ids)->get(['u.id', 'u.email', DB::raw("NULLIF(TRIM(CONCAT(up.first_name, ' ', up.last_name)), '') AS name")]) as $r) {
            $out[$r->id] = $r->name ?? (string) $r->email;
        }

        return $out;
    }
}
