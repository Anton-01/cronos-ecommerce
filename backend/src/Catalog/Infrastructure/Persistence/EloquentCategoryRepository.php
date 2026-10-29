<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Persistence;

use Cronos\Catalog\Domain\Events\CatalogEntityChanged;
use Cronos\Catalog\Domain\Ports\CategoryRepository;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\CategoryModel;
use Cronos\Shared\Domain\DomainEventDispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentCategoryRepository implements CategoryRepository
{
    public function __construct(private readonly DomainEventDispatcher $events) {}

    public function find(string $id): ?array
    {
        $model = $this->query()->with('translations')->find($id);

        return $model ? $this->toRow($model) : null;
    }

    public function all(): array
    {
        return $this->query()->with('translations')->orderByRaw('categories.path')->orderBy('position')->get()->map(fn (CategoryModel $m) => $this->toRow($m))->all();
    }

    public function taxClassExists(int $id): bool
    {
        return DB::table('tax_classes')->where('id', $id)->exists();
    }

    public function create(?string $parentId, int $position, bool $isActive, ?string $imageMediaId, array $translations): string
    {
        return DB::transaction(function () use ($parentId, $position, $isActive, $imageMediaId, $translations): string {
            $id = strtolower((string) Str::ulid());
            $parent = $parentId ? $this->query()->findOrFail($parentId) : null;

            $model = new CategoryModel(['parent_id' => $parentId, 'depth' => ($parent?->depth ?? 0) + 1, 'position' => $position, 'is_active' => $isActive, 'image_media_id' => $imageMediaId]);
            $model->id = $id;
            $model->path = $parent ? $parent->path.'.'.$id : $id;
            $model->save();

            $this->writeTranslations($id, $translations);
            $this->events->dispatch(new CatalogEntityChanged('category', $id));

            return $id;
        });
    }

    public function update(string $id, array $attributes, ?array $translations): void
    {
        DB::transaction(function () use ($id, $attributes, $translations): void {
            $model = CategoryModel::query()->findOrFail($id);
            $model->fill($attributes)->save();

            if ($translations !== null) {
                $this->writeTranslations($id, $translations);
            }
            $this->events->dispatch(new CatalogEntityChanged('category', $id));
        });
    }

    public function move(string $id, ?string $newParentId, int $position): void
    {
        DB::transaction(function () use ($id, $newParentId, $position): void {
            $node = $this->query()->lockForUpdate()->findOrFail($id);
            $parent = $newParentId ? $this->query()->findOrFail($newParentId) : null;
            $oldPath = $node->path;
            $newPath = $parent ? $parent->path.'.'.$id : $id;
            $delta = (($parent?->depth ?? 0) + 1) - $node->depth;

            // One statement rewrites the node and its whole subtree; siblings are untouched.
            DB::update(
                'UPDATE categories SET
                    path = CASE WHEN nlevel(path) = nlevel(?::ltree) THEN ?::ltree ELSE ?::ltree || subpath(path, nlevel(?::ltree)) END,
                    depth = depth + ?, updated_at = now()
                 WHERE path <@ ?::ltree AND deleted_at IS NULL',
                [$oldPath, $newPath, $newPath, $oldPath, $delta, $oldPath],
            );
            DB::table('categories')->where('id', $id)->update(['parent_id' => $newParentId, 'position' => $position]);

            $this->events->dispatch(new CatalogEntityChanged('category', $id));
        });
    }

    public function isDescendantOf(string $candidateId, string $ancestorId): bool
    {
        return DB::selectOne('SELECT 1 AS hit FROM categories c, categories a WHERE c.id = ? AND a.id = ? AND c.path <@ a.path', [$candidateId, $ancestorId]) !== null;
    }

    public function hasChildren(string $id): bool
    {
        return $this->query()->where('parent_id', $id)->exists();
    }

    public function delete(string $id): void
    {
        DB::transaction(function () use ($id): void {
            CategoryModel::query()->findOrFail($id)->delete();
            DB::table('category_translations')->where('category_id', $id)->update(['deleted_at' => now()]);
            DB::table('product_categories')->where('category_id', $id)->delete();
            $this->events->dispatch(new CatalogEntityChanged('category', $id));
        });
    }

    public function slugTaken(string $locale, string $slug, ?string $exceptId = null): bool
    {
        return DB::table('category_translations')->where(['locale' => $locale, 'slug' => $slug])->whereNull('deleted_at')
            ->when($exceptId, fn ($q) => $q->where('category_id', '!=', $exceptId))->exists();
    }

    private function query(): Builder
    {
        return CategoryModel::query()->select('categories.*')->selectRaw('path::text AS path');
    }

    /** @param array<string, array<string, ?string>> $translations replaces the whole set */
    private function writeTranslations(string $id, array $translations): void
    {
        DB::table('category_translations')->where('category_id', $id)->whereNotIn('locale', array_keys($translations))->delete();

        foreach ($translations as $locale => $t) {
            DB::table('category_translations')->upsert([[
                'category_id' => $id, 'locale' => $locale, 'name' => $t['name'], 'slug' => $t['slug'],
                'description' => $t['description'] ?? null, 'meta_title' => $t['meta_title'] ?? null, 'meta_description' => $t['meta_description'] ?? null,
                'deleted_at' => null, 'created_at' => now(), 'updated_at' => now(),
            ]], ['category_id', 'locale'], ['name', 'slug', 'description', 'meta_title', 'meta_description', 'deleted_at', 'updated_at']);
        }
    }

    private function toRow(CategoryModel $m): array
    {
        return [
            'id' => $m->id, 'parent_id' => $m->parent_id, 'path' => (string) $m->path, 'depth' => (int) $m->depth, 'position' => (int) $m->position,
            'is_active' => (bool) $m->is_active, 'image_media_id' => $m->image_media_id, 'default_tax_class_id' => $m->default_tax_class_id,
            'translations' => $m->translations->mapWithKeys(fn ($t) => [$t->locale => [
                'name' => $t->name, 'slug' => $t->slug, 'description' => $t->description, 'meta_title' => $t->meta_title, 'meta_description' => $t->meta_description,
            ]])->all(),
        ];
    }
}
