<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Persistence;

use Cronos\Inventory\Domain\Ports\LocationRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseLocationRepository implements LocationRepository
{
    public function all(int $warehouseId): array
    {
        return DB::table('warehouse_locations')->where('warehouse_id', $warehouseId)->orderBy('depth')->orderBy('sort_order')->orderBy('code')->get()->map(fn ($r) => $this->row($r))->all();
    }

    public function find(string $id): ?array
    {
        $r = DB::table('warehouse_locations')->where('id', $id)->first();

        return $r === null ? null : $this->row($r);
    }

    public function findByCode(int $warehouseId, string $code): ?array
    {
        $r = DB::table('warehouse_locations')->where(['warehouse_id' => $warehouseId, 'code' => $code])->first();

        return $r === null ? null : $this->row($r);
    }

    public function create(array $row): string
    {
        return DB::transaction(function () use ($row): string {
            $id = strtoupper((string) Str::ulid());
            $parent = ($row['parent_id'] ?? null) === null ? null : DB::table('warehouse_locations')->where('id', $row['parent_id'])->first();
            DB::table('warehouse_locations')->insert([
                'id' => $id, 'warehouse_id' => $row['warehouse_id'], 'parent_id' => $row['parent_id'] ?? null, 'code' => $row['code'], 'name' => $row['name'],
                'type' => $row['type'], 'depth' => $parent === null ? 1 : $parent->depth + 1, 'path' => ($parent === null ? '' : $parent->path.'.').$id,
                'sort_order' => $row['sort_order'] ?? 0, 'capacity' => $row['capacity'] ?? null, 'is_pickable' => $row['is_pickable'] ?? true,
                'is_active' => true, 'is_system' => $row['is_system'] ?? false, 'version' => 1, 'created_by' => $row['created_by'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->snapshot($id, $row['created_by'] ?? null);

            return $id;
        });
    }

    public function revise(string $id, array $changes, ?string $userId): void
    {
        DB::transaction(function () use ($id, $changes, $userId): void {
            DB::table('warehouse_locations')->where('id', $id)->lockForUpdate()->first();
            $allowed = array_intersect_key($changes, array_flip(['name', 'type', 'capacity', 'is_pickable', 'is_active', 'sort_order']));
            DB::table('warehouse_locations')->where('id', $id)->update($allowed + ['version' => DB::raw('version + 1'), 'updated_at' => now()]);
            $this->snapshot($id, $userId);
        });
    }

    public function versions(string $id): array
    {
        return DB::table('warehouse_location_versions')->where('location_id', $id)->orderBy('version')->get()
            ->map(fn ($r) => ['version' => (int) $r->version, 'snapshot' => json_decode($r->snapshot, true), 'changed_by' => $r->changed_by, 'changed_at' => $r->changed_at])->all();
    }

    public function subtreeIds(string $id): array
    {
        $path = DB::table('warehouse_locations')->where('id', $id)->value('path');

        return $path === null ? [] : DB::table('warehouse_locations')->where('path', 'like', $path.'%')->pluck('id')->all();
    }

    public function subtreeHeight(string $id): int
    {
        $row = DB::table('warehouse_locations')->where('id', $id)->first();
        if ($row === null) {
            return 0;
        }
        $deepest = (int) DB::table('warehouse_locations')->where('path', 'like', $row->path.'%')->max('depth');

        return $deepest - (int) $row->depth + 1;
    }

    public function stockIn(array $locationIds): string
    {
        return $locationIds === [] ? '0' : (string) (DB::table('location_stock')->whereIn('location_id', $locationIds)->sum('quantity') ?? '0');
    }

    public function relocate(string $id, ?string $newParentId, ?string $userId): void
    {
        DB::transaction(function () use ($id, $newParentId, $userId): void {
            $node = DB::table('warehouse_locations')->where('id', $id)->lockForUpdate()->first();
            $parent = $newParentId === null ? null : DB::table('warehouse_locations')->where('id', $newParentId)->first();
            $oldPath = $node->path;
            $newPath = ($parent === null ? '' : $parent->path.'.').$id;
            $depthShift = ($parent === null ? 1 : $parent->depth + 1) - (int) $node->depth;

            DB::update('UPDATE warehouse_locations SET path = ? || substr(path, ?), depth = depth + ?, updated_at = now() WHERE path LIKE ?', [$newPath, strlen($oldPath) + 1, $depthShift, $oldPath.'%']);
            DB::table('warehouse_locations')->where('id', $id)->update(['parent_id' => $newParentId, 'version' => DB::raw('version + 1')]);
            $this->snapshot($id, $userId);
        });
    }

    private function snapshot(string $id, ?string $userId): void
    {
        $r = DB::table('warehouse_locations')->where('id', $id)->first();
        DB::table('warehouse_location_versions')->insert([
            'location_id' => $id, 'version' => $r->version, 'snapshot' => json_encode($this->row($r), JSON_THROW_ON_ERROR), 'changed_by' => $userId, 'changed_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(object $r): array
    {
        return [
            'id' => $r->id, 'warehouse_id' => (int) $r->warehouse_id, 'parent_id' => $r->parent_id, 'code' => $r->code, 'name' => $r->name, 'type' => $r->type,
            'depth' => (int) $r->depth, 'path' => $r->path, 'sort_order' => (int) $r->sort_order, 'capacity' => $r->capacity === null ? null : (string) $r->capacity,
            'is_pickable' => (bool) $r->is_pickable, 'is_active' => (bool) $r->is_active, 'is_system' => (bool) $r->is_system, 'version' => (int) $r->version,
        ];
    }
}
