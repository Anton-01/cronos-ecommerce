<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Persistence;

use Cronos\Catalog\Domain\Ports\CatalogOptionRepository;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\CatalogOptionModel;
use Illuminate\Support\Facades\DB;

final class EloquentCatalogOptionRepository implements CatalogOptionRepository
{
    /** Where each list is used inside a product's data sheet. */
    private const USED_IN = [
        'packaging_type' => ["specs->>'packaging_type'"],
        'unit' => ["specs->>'base_unit'", "specs->>'presentation_unit'"],
        'weight_unit' => ["specs->'weight'->>'unit'"],
        'length_unit' => ["specs->'length'->>'unit'", "specs->'width'->>'unit'", "specs->'height'->>'unit'"],
    ];

    public function all(string $catalog, bool $includeInactive): array
    {
        return CatalogOptionModel::query()->where('catalog', $catalog)->when(! $includeInactive, fn ($q) => $q->where('is_active', true))->orderBy('position')->orderBy('id')->get()
            ->map(fn ($m) => $this->row($m) + ['in_use' => $this->isInUse($catalog, $m->code)])->all();
    }

    public function find(int $id): ?array
    {
        $m = CatalogOptionModel::query()->find($id);

        return $m ? $this->row($m) : null;
    }

    public function findByCode(string $catalog, string $code): ?array
    {
        $m = CatalogOptionModel::query()->where(['catalog' => $catalog, 'code' => $code])->first();

        return $m ? $this->row($m) : null;
    }

    public function create(array $row): int
    {
        return CatalogOptionModel::query()->create($row)->id;
    }

    public function update(int $id, array $changes): void
    {
        CatalogOptionModel::query()->findOrFail($id)->fill($changes)->save();
    }

    public function delete(int $id): void
    {
        CatalogOptionModel::query()->findOrFail($id)->delete();
    }

    public function isInUse(string $catalog, string $code): bool
    {
        $columns = self::USED_IN[$catalog] ?? [];
        if ($columns === []) {
            return false;
        }

        return DB::table('product_specs')->where(function ($q) use ($columns, $code): void {
            foreach ($columns as $c) {
                $q->orWhereRaw("{$c} = ?", [$code]);
            }
        })->exists();
    }

    /** @return array<string, mixed> */
    private function row(CatalogOptionModel $m): array
    {
        return ['id' => $m->id, 'catalog' => $m->catalog, 'code' => $m->code, 'labels' => $m->labels, 'meta' => $m->meta, 'position' => (int) $m->position, 'is_active' => (bool) $m->is_active];
    }
}
