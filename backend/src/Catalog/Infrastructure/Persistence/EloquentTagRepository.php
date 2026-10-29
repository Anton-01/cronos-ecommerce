<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Persistence;

use Cronos\Catalog\Domain\Events\CatalogEntityChanged;
use Cronos\Catalog\Domain\Ports\TagRepository;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\TagModel;
use Cronos\Shared\Domain\DomainEventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentTagRepository implements TagRepository
{
    public function __construct(private readonly DomainEventDispatcher $events) {}

    public function find(string $id): ?array
    {
        $tag = TagModel::query()->with('translations')->find($id);

        return $tag ? ['id' => $tag->id, 'translations' => $tag->translations->mapWithKeys(fn ($t) => [$t->locale => ['name' => $t->name, 'slug' => $t->slug]])->all()] : null;
    }

    public function create(array $translations): string
    {
        return DB::transaction(function () use ($translations): string {
            $tag = new TagModel;
            $tag->id = strtolower((string) Str::ulid());
            $tag->save();
            $this->write($tag->id, $translations);
            $this->events->dispatch(new CatalogEntityChanged('tag', $tag->id));

            return $tag->id;
        });
    }

    public function update(string $id, array $translations): void
    {
        DB::transaction(function () use ($id, $translations): void {
            $tag = TagModel::query()->findOrFail($id);
            $tag->touch();
            $this->write($id, $translations);
            $this->events->dispatch(new CatalogEntityChanged('tag', $id));
        });
    }

    public function delete(string $id): void
    {
        DB::transaction(function () use ($id): void {
            TagModel::query()->findOrFail($id)->delete();
            DB::table('tag_translations')->where('tag_id', $id)->update(['deleted_at' => now()]);
            DB::table('product_tag')->where('tag_id', $id)->delete();
            $this->events->dispatch(new CatalogEntityChanged('tag', $id));
        });
    }

    public function slugTaken(string $locale, string $slug, ?string $exceptId = null): bool
    {
        return DB::table('tag_translations')->where(['locale' => $locale, 'slug' => $slug])->whereNull('deleted_at')
            ->when($exceptId, fn ($q) => $q->where('tag_id', '!=', $exceptId))->exists();
    }

    /** @param array<string, array{name: string, slug: string}> $translations */
    private function write(string $id, array $translations): void
    {
        DB::table('tag_translations')->where('tag_id', $id)->whereNotIn('locale', array_keys($translations))->delete();

        foreach ($translations as $locale => $t) {
            DB::table('tag_translations')->upsert(
                [['tag_id' => $id, 'locale' => $locale, 'name' => $t['name'], 'slug' => $t['slug'], 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()]],
                ['tag_id', 'locale'],
                ['name', 'slug', 'deleted_at', 'updated_at'],
            );
        }
    }
}
