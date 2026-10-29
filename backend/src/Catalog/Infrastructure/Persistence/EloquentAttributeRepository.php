<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Persistence;

use Cronos\Catalog\Domain\Events\CatalogEntityChanged;
use Cronos\Catalog\Domain\Ports\AttributeRepository;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\AttributeModel;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\AttributeValueModel;
use Cronos\Shared\Domain\DomainEventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentAttributeRepository implements AttributeRepository
{
    public function __construct(private readonly DomainEventDispatcher $events) {}

    public function find(string $id): ?array
    {
        $a = AttributeModel::query()->with('translations')->find($id);

        return $a ? [
            'id' => $a->id, 'code' => $a->code, 'type' => $a->type, 'is_filterable' => (bool) $a->is_filterable, 'position' => (int) $a->position,
            'translations' => $a->translations->pluck('name', 'locale')->all(),
        ] : null;
    }

    public function create(string $code, string $type, bool $isFilterable, int $position, array $names): string
    {
        return DB::transaction(function () use ($code, $type, $isFilterable, $position, $names): string {
            $a = new AttributeModel(['code' => $code, 'type' => $type, 'is_filterable' => $isFilterable, 'position' => $position]);
            $a->id = strtolower((string) Str::ulid());
            $a->save();
            $this->writeNames($a->id, $names);
            $this->events->dispatch(new CatalogEntityChanged('attribute', $a->id));

            return $a->id;
        });
    }

    public function update(string $id, array $attributes, ?array $names): void
    {
        DB::transaction(function () use ($id, $attributes, $names): void {
            AttributeModel::query()->findOrFail($id)->fill($attributes)->save();
            if ($names !== null) {
                $this->writeNames($id, $names);
            }
            $this->events->dispatch(new CatalogEntityChanged('attribute', $id));
        });
    }

    public function delete(string $id): void
    {
        DB::transaction(function () use ($id): void {
            AttributeModel::query()->findOrFail($id)->values()->get()->each->delete();
            AttributeModel::query()->findOrFail($id)->delete();
            $this->events->dispatch(new CatalogEntityChanged('attribute', $id));
        });
    }

    public function codeTaken(string $code, ?string $exceptId = null): bool
    {
        return AttributeModel::query()->where('code', $code)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists();
    }

    public function isInUse(string $id): bool
    {
        return DB::table('product_attributes')->join('products', 'products.id', '=', 'product_attributes.product_id')
            ->where('product_attributes.attribute_id', $id)->whereNull('products.deleted_at')->exists();
    }

    public function addValue(string $attributeId, string $code, ?array $meta, int $position, array $labels): string
    {
        return DB::transaction(function () use ($attributeId, $code, $meta, $position, $labels): string {
            $v = new AttributeValueModel(['attribute_id' => $attributeId, 'code' => $code, 'meta' => $meta, 'position' => $position]);
            $v->id = strtolower((string) Str::ulid());
            $v->save();
            $this->writeLabels($v->id, $labels);
            $this->events->dispatch(new CatalogEntityChanged('attribute', $attributeId));

            return $v->id;
        });
    }

    public function updateValue(string $valueId, array $attributes, ?array $labels): void
    {
        DB::transaction(function () use ($valueId, $attributes, $labels): void {
            $v = AttributeValueModel::query()->findOrFail($valueId);
            $v->fill($attributes)->save();
            if ($labels !== null) {
                $this->writeLabels($valueId, $labels);
            }
            $this->events->dispatch(new CatalogEntityChanged('attribute', $v->attribute_id));
        });
    }

    public function deleteValue(string $valueId): void
    {
        $v = AttributeValueModel::query()->findOrFail($valueId);
        $v->delete();
        $this->events->dispatch(new CatalogEntityChanged('attribute', $v->attribute_id));
    }

    public function valueCodeTaken(string $attributeId, string $code, ?string $exceptId = null): bool
    {
        return AttributeValueModel::query()->where(['attribute_id' => $attributeId, 'code' => $code])->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists();
    }

    public function isValueInUse(string $valueId): bool
    {
        return DB::table('product_attribute_values')->join('products', 'products.id', '=', 'product_attribute_values.product_id')
            ->where('product_attribute_values.attribute_value_id', $valueId)->whereNull('products.deleted_at')->exists();
    }

    public function findValue(string $valueId): ?array
    {
        $v = AttributeValueModel::query()->find($valueId);

        return $v ? ['id' => $v->id, 'attribute_id' => $v->attribute_id] : null;
    }

    public function codesOfValues(array $valueIds): array
    {
        return AttributeValueModel::query()->whereKey($valueIds)->pluck('code', 'id')->all();
    }

    public function attributeIdsOfValues(array $valueIds): array
    {
        return AttributeValueModel::query()->whereKey($valueIds)->pluck('attribute_id', 'id')->all();
    }

    /** @param array<string, string> $names */
    private function writeNames(string $id, array $names): void
    {
        DB::table('attribute_translations')->where('attribute_id', $id)->whereNotIn('locale', array_keys($names))->delete();
        foreach ($names as $locale => $name) {
            DB::table('attribute_translations')->upsert([['attribute_id' => $id, 'locale' => $locale, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]], ['attribute_id', 'locale'], ['name', 'updated_at']);
        }
    }

    /** @param array<string, string> $labels */
    private function writeLabels(string $id, array $labels): void
    {
        DB::table('attribute_value_translations')->where('attribute_value_id', $id)->whereNotIn('locale', array_keys($labels))->delete();
        foreach ($labels as $locale => $label) {
            DB::table('attribute_value_translations')->upsert([['attribute_value_id' => $id, 'locale' => $locale, 'label' => $label, 'created_at' => now(), 'updated_at' => now()]], ['attribute_value_id', 'locale'], ['label', 'updated_at']);
        }
    }
}
