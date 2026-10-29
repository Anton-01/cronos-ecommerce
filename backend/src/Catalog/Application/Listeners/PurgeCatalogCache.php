<?php

declare(strict_types=1);

namespace Cronos\Catalog\Application\Listeners;

use Cronos\Catalog\Application\CatalogTags;
use Cronos\Catalog\Domain\Events\CatalogEntityChanged;
use Cronos\Catalog\Domain\Ports\CatalogCache;
use Cronos\Media\Domain\Events\MediaChanged;
use Cronos\Media\Domain\Ports\MediaRepository;
use Illuminate\Support\Facades\DB;

/**
 * Purges exactly what a change can affect, and only after the transaction commits
 * (purging earlier would let a reader re-cache the old data).
 */
final class PurgeCatalogCache
{
    public function __construct(
        private readonly CatalogCache $cache,
        private readonly MediaRepository $media,
    ) {}

    public function handle(CatalogEntityChanged $event): void
    {
        DB::afterCommit(fn () => $this->cache->flush($this->tagsFor($event)));
    }

    /** A media file changed: purge the product (or category) that shows it. */
    public function handleMedia(MediaChanged $event): void
    {
        $owner = $this->media->ownerOf($event->mediaId);

        if ($owner === null || $owner['model_id'] === null) {
            return;
        }

        $this->handle(new CatalogEntityChanged(str_contains((string) $owner['model_type'], 'Category') ? 'category' : 'product', $owner['model_id']));
    }

    /** @return list<string> */
    private function tagsFor(CatalogEntityChanged $event): array
    {
        return match ($event->type) {
            'product' => $this->productTags($event->id),
            'category' => [CatalogTags::CATEGORIES, CatalogTags::TREE, CatalogTags::LISTS, CatalogTags::PRODUCTS],
            // Tags and attributes are embedded in many product views; they change rarely, so purge them all.
            default => [CatalogTags::PRODUCTS, CatalogTags::LISTS, CatalogTags::CATEGORIES],
        };
    }

    /** The product itself, the parent whose page lists it as a variation, and groups that embed it. @return list<string> */
    private function productTags(string $id): array
    {
        $tags = [CatalogTags::product($id), CatalogTags::LISTS];

        $parent = DB::table('products')->where('id', $id)->value('parent_id');
        $parent && $tags[] = CatalogTags::product($parent);

        foreach (DB::table('product_group_items')->whereIn('product_id', array_filter([$id, $parent]))->pluck('group_id') as $groupId) {
            $tags[] = CatalogTags::product($groupId);
        }

        return array_values(array_unique($tags));
    }
}
