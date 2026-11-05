<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Persistence;

use Cronos\Inventory\Domain\Ports\OperationsStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseOperationsStore implements OperationsStore
{
    public function atomically(callable $work): mixed
    {
        return DB::transaction($work);
    }

    public function advisoryLock(string $key): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
    }

    public function pickedFor(string $orderId, string $productId, int $warehouseId): string
    {
        return (string) (DB::table('location_movements')->where(['reference_type' => 'order', 'reference_id' => $orderId, 'product_id' => $productId, 'warehouse_id' => $warehouseId, 'type' => 'pick'])->sum(DB::raw('-quantity')) ?? '0');
    }

    public function pickLines(?int $warehouseId, int $limit = 200): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT s.reference_id AS order_id, s.product_id, s.warehouse_id, s.sold, COALESCE(r.returned, 0) AS returned, COALESCE(p.picked, 0) AS picked
            FROM (SELECT reference_id, product_id, warehouse_id, SUM(-quantity) AS sold FROM inventory_movements WHERE reference_type = 'order' GROUP BY 1, 2, 3) s
            LEFT JOIN (SELECT reference_id, product_id, warehouse_id, SUM(quantity) AS returned FROM inventory_movements WHERE reference_type = 'order_return' GROUP BY 1, 2, 3) r USING (reference_id, product_id, warehouse_id)
            LEFT JOIN (SELECT reference_id, product_id, warehouse_id, SUM(-quantity) AS picked FROM location_movements WHERE reference_type = 'order' AND type = 'pick' GROUP BY 1, 2, 3) p USING (reference_id, product_id, warehouse_id)
            WHERE (?::int IS NULL OR s.warehouse_id = ?::int) AND s.sold - COALESCE(r.returned, 0) - COALESCE(p.picked, 0) > 0
            ORDER BY s.reference_id, s.product_id LIMIT ?::int
        SQL, [$warehouseId, $warehouseId, $limit]);

        return array_map(static fn ($r) => ['order_id' => $r->order_id, 'product_id' => $r->product_id, 'warehouse_id' => (int) $r->warehouse_id, 'sold' => (string) $r->sold, 'returned' => (string) $r->returned, 'picked' => (string) $r->picked], $rows);
    }

    public function createRequest(array $row): string
    {
        $id = strtolower((string) Str::ulid());
        DB::table('stock_adjustment_requests')->insert(['id' => $id, 'status' => 'pending'] + $row);

        return $id;
    }

    public function findRequest(string $id, bool $lock = false): ?array
    {
        $q = DB::table('stock_adjustment_requests')->where('id', $id);
        $lock && $q->lockForUpdate();
        $r = $q->first();

        return $r === null ? null : $this->plain($r);
    }

    public function requestByKey(string $key): ?array
    {
        $r = DB::table('stock_adjustment_requests')->where('idempotency_key', $key)->first();

        return $r === null ? null : $this->plain($r);
    }

    public function decideRequest(string $id, string $status, ?string $userId, ?string $note, ?string $movementUlid): void
    {
        DB::table('stock_adjustment_requests')->where('id', $id)->update(['status' => $status, 'decided_by' => $userId, 'decided_at' => now(), 'decision_note' => $note, 'movement_ulid' => $movementUlid]);
    }

    public function requests(?string $status, ?int $warehouseId, int $limit = 100): array
    {
        return DB::table('stock_adjustment_requests')->when($status, fn ($q) => $q->where('status', $status))->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->orderByDesc('requested_at')->limit($limit)->get()->map(fn ($r) => $this->plain($r))->all();
    }

    public function createSession(array $row): string
    {
        $id = strtolower((string) Str::ulid());
        DB::table('count_sessions')->insert(['id' => $id, 'status' => 'open'] + $row);

        return $id;
    }

    public function snapshotSession(string $sessionId, int $warehouseId, ?string $scopeLocationId): int
    {
        $scope = $scopeLocationId === null ? null : DB::table('warehouse_locations')->where('id', $scopeLocationId)->value('path');

        return DB::affectingStatement(
            'INSERT INTO count_session_snapshots (session_id, product_id, location_id, quantity) SELECT ?, ls.product_id, ls.location_id, ls.quantity FROM location_stock ls JOIN warehouse_locations l ON l.id = ls.location_id WHERE l.warehouse_id = ? AND (?::text IS NULL OR l.path LIKE ?::text || \'%\')',
            [$sessionId, $warehouseId, $scope, $scope],
        );
    }

    public function findSession(string $id): ?array
    {
        $r = DB::table('count_sessions')->where('id', $id)->first();

        return $r === null ? null : $this->plain($r);
    }

    public function closeSession(string $id, ?string $userId): void
    {
        DB::table('count_sessions')->where('id', $id)->update(['status' => 'closed', 'closed_by' => $userId, 'closed_at' => now()]);
    }

    public function snapshotQuantity(string $sessionId, string $productId, string $locationId): string
    {
        return (string) (DB::table('count_session_snapshots')->where(['session_id' => $sessionId, 'product_id' => $productId, 'location_id' => $locationId])->value('quantity') ?? '0');
    }

    public function entriesFor(string $sessionId, string $productId, string $locationId): array
    {
        return DB::table('count_entries')->where(['session_id' => $sessionId, 'product_id' => $productId, 'location_id' => $locationId])->orderBy('counted_at')->orderBy('id')->get()->map(fn ($r) => $this->plain($r))->all();
    }

    public function createEntry(array $row): string
    {
        $id = strtolower((string) Str::ulid());
        DB::table('count_entries')->insert(['id' => $id] + $row);

        return $id;
    }

    public function findEntry(string $id, bool $lock = false): ?array
    {
        $q = DB::table('count_entries')->where('id', $id);
        $lock && $q->lockForUpdate();
        $r = $q->first();

        return $r === null ? null : $this->plain($r);
    }

    public function entryByKey(string $key): ?array
    {
        $r = DB::table('count_entries')->where('idempotency_key', $key)->first();

        return $r === null ? null : $this->plain($r);
    }

    public function reviewEntry(string $id, string $status, ?string $userId, ?string $note, ?string $movementUlid): void
    {
        DB::table('count_entries')->where('id', $id)->update(['status' => $status, 'reviewed_by' => $userId, 'reviewed_at' => now(), 'review_note' => $note, 'movement_ulid' => $movementUlid]);
    }

    public function appliedCountDelta(string $sessionId, string $productId, string $locationId): string
    {
        return (string) (DB::table('location_movements')->where(['reference_type' => 'count_session', 'reference_id' => $sessionId, 'product_id' => $productId, 'location_id' => $locationId])->sum('quantity') ?? '0');
    }

    public function entriesInReview(?string $sessionId): int
    {
        return DB::table('count_entries')->where('status', 'in_review')->when($sessionId, fn ($q) => $q->where('session_id', $sessionId))->count();
    }

    public function reviewQueue(?string $sessionId, int $limit = 100): array
    {
        return DB::table('count_entries')->where('status', 'in_review')->when($sessionId, fn ($q) => $q->where('session_id', $sessionId))->orderBy('counted_at')->limit($limit)->get()->map(fn ($r) => $this->plain($r))->all();
    }

    /** @return array<string, mixed> */
    private function plain(object $r): array
    {
        return (array) $r;
    }
}
