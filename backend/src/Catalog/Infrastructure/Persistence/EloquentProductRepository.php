<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Persistence;

use Cronos\Catalog\Domain\Events\CatalogEntityChanged;
use Cronos\Catalog\Domain\Ports\ProductRepository;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\ProductModel;
use Cronos\Shared\Domain\DomainEventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentProductRepository implements ProductRepository
{
    private const SALES_COLUMNS = ['sell_unit', 'quantity_step', 'min_quantity', 'max_quantity', 'sell_by_cut', 'sell_by_roll', 'roll_length', 'roll_price'];

    public function __construct(private readonly DomainEventDispatcher $events) {}

    public function find(string $id): ?array
    {
        $model = ProductModel::query()->with(['translations', 'categories', 'tags'])->find($id);

        return $model ? $this->toRow($model) : null;
    }

    public function variationsOf(string $parentId): array
    {
        return ProductModel::query()->with(['translations', 'categories', 'tags'])->where('parent_id', $parentId)->orderBy('position')->get()
            ->map(fn (ProductModel $m) => $this->toRow($m))->all();
    }

    public function create(array $row, array $translations): string
    {
        return DB::transaction(function () use ($row, $translations): string {
            $product = new ProductModel($row);
            $product->id = strtolower((string) Str::ulid());
            $product->save();
            $this->saveTranslations($product->id, $translations);
            $this->changed($product->id);

            return $product->id;
        });
    }

    public function update(string $id, array $columns): void
    {
        ProductModel::query()->findOrFail($id)->fill($columns)->save();
        $this->changed($id);
    }

    public function saveTranslations(string $id, array $translations): void
    {
        foreach ($translations as $locale => $t) {
            if ($t === null) {
                DB::table('product_translations')->where(['product_id' => $id, 'locale' => $locale])->delete();

                continue;
            }

            DB::table('product_translations')->upsert([[
                'product_id' => $id, 'locale' => $locale, 'name' => $t['name'], 'slug' => $t['slug'],
                'short_description' => $t['short_description'] ?? null, 'description' => $t['description'] ?? null,
                'meta_title' => $t['meta_title'] ?? null, 'meta_description' => $t['meta_description'] ?? null,
                'deleted_at' => null, 'created_at' => now(), 'updated_at' => now(),
            ]], ['product_id', 'locale'], ['name', 'slug', 'short_description', 'description', 'meta_title', 'meta_description', 'deleted_at', 'updated_at']);
        }
        $this->changed($id);
    }

    public function delete(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $ids = [$id, ...ProductModel::query()->where('parent_id', $id)->pluck('id')->all()];

            foreach (ProductModel::query()->whereKey($ids)->get() as $product) {
                $product->delete();
            }
            DB::table('product_translations')->whereIn('product_id', $ids)->update(['deleted_at' => now()]);
            DB::table('product_group_items')->whereIn('product_id', $ids)->orWhereIn('group_id', $ids)->delete();

            foreach ($ids as $deleted) {
                $this->changed($deleted);
            }
        });
    }

    public function skuTaken(string $sku, ?string $exceptId = null): bool
    {
        return ProductModel::query()->where('sku', $sku)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists();
    }

    public function slugTaken(string $locale, string $slug, ?string $exceptId = null): bool
    {
        return DB::table('product_translations')->where(['locale' => $locale, 'slug' => $slug])->whereNull('deleted_at')
            ->when($exceptId, fn ($q) => $q->where('product_id', '!=', $exceptId))->exists();
    }

    public function setCategories(string $id, array $categoryIds, ?string $primaryId): void
    {
        $sync = [];
        foreach (array_values(array_unique($categoryIds)) as $i => $categoryId) {
            $sync[$categoryId] = ['is_primary' => $categoryId === ($primaryId ?? $categoryIds[0] ?? null), 'position' => $i];
        }

        ProductModel::query()->findOrFail($id)->categories()->sync($sync);
        $this->changed($id);
    }

    public function setTags(string $id, array $tagIds): void
    {
        ProductModel::query()->findOrFail($id)->tags()->sync(array_values(array_unique($tagIds)));
        $this->changed($id);
    }

    public function setAttributes(string $id, array $attributes): void
    {
        DB::transaction(function () use ($id, $attributes): void {
            DB::table('product_attributes')->where('product_id', $id)->delete();
            foreach (array_values($attributes) as $i => $a) {
                DB::table('product_attributes')->insert(['product_id' => $id, 'attribute_id' => $a['attribute_id'], 'is_variation' => $a['is_variation'], 'is_visible' => $a['is_visible'], 'position' => $i]);
            }
        });
        $this->changed($id);
    }

    public function setValues(string $id, array $valueIds): void
    {
        ProductModel::query()->findOrFail($id)->attributeValues()->sync(array_values(array_unique($valueIds)));
        $this->changed($id);
    }

    public function setGroupItems(string $id, array $items): void
    {
        DB::transaction(function () use ($id, $items): void {
            DB::table('product_group_items')->where('group_id', $id)->delete();
            foreach (array_values($items) as $i => $item) {
                DB::table('product_group_items')->insert(['group_id' => $id, 'product_id' => $item['product_id'], 'default_quantity' => $item['default_quantity'], 'position' => $i]);
            }
        });
        $this->changed($id);
    }

    public function createVariation(string $parentId, array $row, array $valueIds, string $combinationHash): string
    {
        return DB::transaction(function () use ($parentId, $row, $valueIds, $combinationHash): string {
            $variation = new ProductModel([...$row, 'parent_id' => $parentId, 'type' => 'variation', 'combination_hash' => $combinationHash]);
            $variation->id = strtolower((string) Str::ulid());
            $variation->save();
            $variation->attributeValues()->sync($valueIds);
            $this->changed($variation->id);
            $this->changed($parentId);

            return $variation->id;
        });
    }

    public function combinationExists(string $parentId, string $combinationHash, ?string $exceptId = null): bool
    {
        return ProductModel::query()->where(['parent_id' => $parentId, 'combination_hash' => $combinationHash])->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists();
    }

    public function existsAndLive(string $id): bool
    {
        return ProductModel::query()->whereKey($id)->exists();
    }

    public function liveIds(array $ids): array
    {
        return ProductModel::query()->whereKey($ids)->pluck('id')->all();
    }

    public function nextVariationPosition(string $parentId): int
    {
        return (int) ProductModel::query()->where('parent_id', $parentId)->max('position') + 1;
    }

    public function countPublishedVariations(string $parentId): int
    {
        return ProductModel::query()->where(['parent_id' => $parentId, 'status' => 'published'])->count();
    }

    public function markPublished(string $id, string $status): void
    {
        $product = ProductModel::query()->findOrFail($id);
        $product->status = $status;
        if ($status === 'published' && $product->published_at === null) {
            $product->published_at = now();
        }
        $product->save();
        if ($product->parent_id) {
            $this->changed($product->parent_id);
        }
        $this->changed($id);
    }

    private function changed(string $id): void
    {
        $this->events->dispatch(new CatalogEntityChanged('product', $id));
    }

    private function toRow(ProductModel $m): array
    {
        $attributes = DB::table('product_attributes')->where('product_id', $m->id)->orderBy('position')->get()
            ->map(fn ($a) => ['attribute_id' => $a->attribute_id, 'is_variation' => (bool) $a->is_variation, 'is_visible' => (bool) $a->is_visible, 'position' => (int) $a->position])->all();
        $primary = $m->categories->first(fn ($c) => (bool) $c->pivot->is_primary);

        return [
            'id' => $m->id, 'parent_id' => $m->parent_id, 'type' => $m->type, 'status' => $m->status, 'sku' => $m->sku, 'barcode' => $m->barcode,
            'price' => $m->price, 'weight' => $m->weight, 'dimensions' => $m->dimensions,
            'sales' => array_intersect_key($m->getAttributes(), array_flip(self::SALES_COLUMNS)),
            'manage_stock' => (bool) $m->manage_stock, 'allow_backorder' => (bool) $m->allow_backorder, 'low_stock_threshold' => $m->low_stock_threshold,
            'stock_quantity' => (string) $m->stock_quantity, 'is_customizable' => (bool) $m->is_customizable, 'position' => (int) $m->position,
            'published_at' => $m->published_at?->toDateTimeString(),
            'translations' => $m->translations->mapWithKeys(fn ($t) => [$t->locale => [
                'name' => $t->name, 'slug' => $t->slug, 'short_description' => $t->short_description, 'description' => $t->description,
                'meta_title' => $t->meta_title, 'meta_description' => $t->meta_description,
            ]])->all(),
            'category_ids' => $m->categories->pluck('id')->all(), 'primary_category_id' => $primary?->id,
            'tag_ids' => $m->tags->pluck('id')->all(),
            'attributes' => $attributes,
            'value_ids' => DB::table('product_attribute_values')->where('product_id', $m->id)->pluck('attribute_value_id')->all(),
            'group_items' => DB::table('product_group_items')->where('group_id', $m->id)->orderBy('position')->get()
                ->map(fn ($g) => ['product_id' => $g->product_id, 'default_quantity' => (string) $g->default_quantity, 'position' => (int) $g->position])->all(),
        ];
    }
}
