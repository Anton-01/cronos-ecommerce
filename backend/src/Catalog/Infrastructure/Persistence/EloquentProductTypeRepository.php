<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Persistence;

use Cronos\Catalog\Domain\Ports\ProductTypeRepository;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\ProductTypeModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentProductTypeRepository implements ProductTypeRepository
{
    public function all(bool $includeInactive): array
    {
        return ProductTypeModel::query()->when(! $includeInactive, fn ($q) => $q->where('is_active', true))->orderBy('position')->orderBy('code')->get()->map(fn ($m) => $this->row($m))->all();
    }

    public function find(string $id): ?array
    {
        $m = ProductTypeModel::query()->find($id);

        return $m ? $this->row($m) : null;
    }

    public function codeTaken(string $code, ?string $exceptId = null): bool
    {
        return ProductTypeModel::query()->where('code', $code)->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->exists();
    }

    public function create(array $row): string
    {
        $m = new ProductTypeModel($row);
        $m->id = strtolower((string) Str::ulid());
        $m->save();

        return $m->id;
    }

    public function update(string $id, array $changes): void
    {
        ProductTypeModel::query()->findOrFail($id)->fill($changes)->save();
    }

    public function delete(string $id): void
    {
        ProductTypeModel::query()->findOrFail($id)->delete();
    }

    public function isInUse(string $id): bool
    {
        return DB::table('product_specs')->where('product_type_id', $id)->exists();
    }

    /** @return array<string, mixed> */
    private function row(ProductTypeModel $m): array
    {
        return [
            'id' => $m->id, 'code' => $m->code, 'names' => $m->names, 'sku_prefix' => $m->sku_prefix, 'sales' => $m->sales, 'required_fields' => $m->required_fields ?? [],
            'variant_attribute_ids' => $m->variant_attribute_ids ?? [], 'product_attribute_ids' => $m->product_attribute_ids ?? [], 'is_active' => (bool) $m->is_active, 'position' => (int) $m->position,
        ];
    }
}
