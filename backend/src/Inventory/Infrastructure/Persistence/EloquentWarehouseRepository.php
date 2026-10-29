<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Persistence;

use Cronos\Inventory\Domain\Ports\WarehouseRepository;
use Cronos\Inventory\Infrastructure\Persistence\Eloquent\WarehouseModel;
use Illuminate\Support\Facades\DB;

final class EloquentWarehouseRepository implements WarehouseRepository
{
    public function find(int $id): ?array
    {
        $m = WarehouseModel::query()->find($id);

        return $m ? $this->row($m) : null;
    }

    public function all(): array
    {
        return WarehouseModel::query()->orderByDesc('is_default')->orderBy('code')->get()->map(fn ($m) => $this->row($m))->all();
    }

    public function candidatesFor(?string $marketCode): array
    {
        $marketId = $marketCode === null ? null : DB::table('markets')->where('code', strtoupper($marketCode))->value('id');

        return WarehouseModel::query()->where('is_active', true)->get()
            ->sortBy(fn ($m) => [$marketId !== null && $m->market_id === $marketId ? 0 : ($m->is_default ? 1 : 2), $m->id])
            ->map(fn ($m) => $this->row($m))->values()->all();
    }

    public function create(array $data): int
    {
        return DB::transaction(function () use ($data): int {
            ! ($data['is_default'] ?? false) || WarehouseModel::query()->where('is_default', true)->update(['is_default' => false]);

            return WarehouseModel::query()->create($this->columns($data))->id;
        });
    }

    public function update(int $id, array $data): void
    {
        DB::transaction(function () use ($id, $data): void {
            ! ($data['is_default'] ?? false) || WarehouseModel::query()->where('is_default', true)->where('id', '<>', $id)->update(['is_default' => false]);
            WarehouseModel::query()->findOrFail($id)->update($this->columns($data));
        });
    }

    public function delete(int $id): void
    {
        WarehouseModel::query()->findOrFail($id)->delete();
    }

    public function codeTaken(string $code, ?int $exceptId = null): bool
    {
        return WarehouseModel::query()->where('code', $code)->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->exists();
    }

    public function hasStock(int $id): bool
    {
        return DB::table('inventory_movements')->where('warehouse_id', $id)->exists() || DB::table('stock_levels')->where('warehouse_id', $id)->where(fn ($q) => $q->where('on_hand', '<>', 0)->orWhere('reserved', '<>', 0))->exists();
    }

    public function marketExists(string $code): bool
    {
        return DB::table('markets')->where('code', strtoupper($code))->exists();
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function columns(array $data): array
    {
        if (array_key_exists('market_code', $data)) {
            $data['market_id'] = $data['market_code'] === null ? null : DB::table('markets')->where('code', $data['market_code'])->value('id');
            unset($data['market_code']);
        }
        unset($data['id']);

        return $data;
    }

    /** @return array<string, mixed> */
    private function row(WarehouseModel $m): array
    {
        return [
            'id' => $m->id, 'code' => $m->code, 'name' => $m->name, 'address' => $m->address ?? [], 'market_id' => $m->market_id,
            'market_code' => $m->market_id === null ? null : DB::table('markets')->where('id', $m->market_id)->value('code'),
            'is_default' => $m->is_default, 'is_active' => $m->is_active,
        ];
    }
}
