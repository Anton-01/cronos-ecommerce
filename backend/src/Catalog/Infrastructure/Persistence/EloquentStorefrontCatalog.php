<?php

declare(strict_types=1);

namespace Cronos\Catalog\Infrastructure\Persistence;

use Cronos\Catalog\Domain\Ports\StorefrontCatalog;
use Cronos\Catalog\Domain\Services\VariationInheritance;
use Cronos\Catalog\Domain\ValueObjects\SalesUnit;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\AttributeModel;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\CategoryModel;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\ProductModel;
use Cronos\Media\Infrastructure\Persistence\Eloquent\MediaModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentStorefrontCatalog implements StorefrontCatalog
{
    /** Lowest price a customer can pay: the product's own, or the cheapest published variation. */
    private const PRICE_EXPR = "COALESCE((SELECT MIN(COALESCE(v.price, products.price)) FROM products v WHERE v.parent_id = products.id AND v.status = 'published' AND v.deleted_at IS NULL), products.price)";

    public function categoryTree(string $locale): array
    {
        $rows = CategoryModel::query()->select('categories.*')->selectRaw('path::text AS path')->with('translations')
            ->where('is_active', true)->orderByRaw('categories.path')->orderBy('position')->get();

        $byParent = $rows->groupBy(fn (CategoryModel $c) => $c->parent_id ?? '');
        $visible = $rows->keyBy('id');
        $build = function (string $parentKey) use (&$build, $byParent, $visible, $locale): array {
            return $byParent->get($parentKey, collect())
                // A category is shown only if every ancestor is active too (inactive parents hide their subtree).
                ->filter(fn (CategoryModel $c) => $c->parent_id === null || $visible->has($c->parent_id))
                ->map(fn (CategoryModel $c) => $this->categoryNode($c, $locale) + ['children' => $build($c->id)])
                ->values()->all();
        };

        return $build('');
    }

    public function category(string $locale, string $slug): ?array
    {
        $id = DB::table('category_translations')->whereNull('deleted_at')->where('slug', $slug)
            ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [$locale])->value('category_id');
        $category = $id ? CategoryModel::query()->select('categories.*')->selectRaw('path::text AS path')->with('translations')->find($id) : null;

        if ($category === null || ! $this->chainActive($category->path)) {
            return null;
        }

        $ancestors = CategoryModel::query()->select('categories.*')->selectRaw('path::text AS path')->with('translations')
            ->whereRaw('path @> ?::ltree AND id <> ?', [$category->path, $category->id])->orderByRaw('nlevel(path)')->get();
        $children = CategoryModel::query()->select('categories.*')->selectRaw('path::text AS path')->with('translations')
            ->where('parent_id', $category->id)->where('is_active', true)->orderBy('position')->get();
        $t = $this->pick($category->translations, $locale);

        return $this->categoryNode($category, $locale) + [
            'description' => $t?->description,
            'breadcrumb' => $ancestors->map(fn (CategoryModel $a) => $this->categoryNode($a, $locale))->all(),
            'children' => $children->map(fn (CategoryModel $c) => $this->categoryNode($c, $locale))->all(),
            'seo' => ['title' => $t?->meta_title ?? $t?->name, 'description' => $t?->meta_description],
            'image' => $category->image_media_id ? $this->mediaPayload(MediaModel::query()->find($category->image_media_id), $locale) : null,
        ];
    }

    public function productIdBySlug(string $locale, string $slug): ?string
    {
        return DB::table('product_translations')->whereNull('deleted_at')->where('slug', $slug)
            ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [$locale])->value('product_id');
    }

    public function product(string $locale, string $id, bool $preview = false): ?array
    {
        $product = ProductModel::query()->with([
            'translations', 'categories.translations', 'tags.translations', 'gallery.translations',
            'variations' => fn ($q) => $preview ? $q : $q->where('status', 'published'),
            'variations.attributeValues', 'variations.gallery.translations',
        ])->find($id);

        if ($product === null || $product->type === 'variation') {
            return null;
        }
        if (! $preview && ($product->status !== 'published' || ($product->type === 'variable' && $product->variations->isEmpty()))) {
            return null;
        }

        return $this->productView($product, $locale);
    }

    public function products(string $locale, array $filter, string $sort, int $limit, int $offset): array
    {
        $query = $this->visible();
        $this->applyFilters($query, $locale, $filter);
        $total = (clone $query)->count();

        $this->applySort($query, $locale, $sort, $filter['search'] ?? null);
        $ids = $query->offset($offset)->limit($limit)->pluck('products.id')->all();

        return ['items' => $this->summaries($locale, $ids), 'total' => $total];
    }

    // ---------------------------------------------------------------- listing

    private function visible(): Builder
    {
        return ProductModel::query()
            ->whereIn('products.type', ['simple', 'variable', 'grouped'])
            ->where('products.status', 'published')
            // A variable product with nothing published to buy is not shown.
            ->where(fn (Builder $q) => $q->where('products.type', '<>', 'variable')->orWhereExists(fn ($e) => $e->selectRaw('1')->from('products as v')
                ->whereColumn('v.parent_id', 'products.id')->where('v.status', 'published')->whereNull('v.deleted_at')));
    }

    /** @param array<string, mixed> $filter */
    private function applyFilters(Builder $query, string $locale, array $filter): void
    {
        if (! empty($filter['category'])) {
            $category = $this->category($locale, $filter['category']);
            $path = $category ? CategoryModel::query()->selectRaw('path::text AS path')->whereKey($category['id'])->value('path') : null;
            $path === null
                ? $query->whereRaw('1 = 0')
                : $query->whereExists(fn ($e) => $e->selectRaw('1')->from('product_categories as pc')->join('categories as c', 'c.id', '=', 'pc.category_id')
                    ->whereColumn('pc.product_id', 'products.id')->whereNull('c.deleted_at')->where('c.is_active', true)->whereRaw('c.path <@ ?::ltree', [$path]));
        }

        if (! empty($filter['tag'])) {
            $query->whereExists(fn ($e) => $e->selectRaw('1')->from('product_tag as pt')->join('tag_translations as tt', 'tt.tag_id', '=', 'pt.tag_id')
                ->whereColumn('pt.product_id', 'products.id')->where('tt.slug', $filter['tag'])->whereNull('tt.deleted_at'));
        }

        if (! empty($filter['search']) && trim($filter['search']) !== '') {
            $term = trim($filter['search']);
            $cfg = $this->tsConfig($locale);
            $query->where(fn (Builder $q) => $q
                ->whereExists(fn ($e) => $e->selectRaw('1')->from('product_translations as s')->whereColumn('s.product_id', 'products.id')->whereNull('s.deleted_at')
                    ->whereRaw("(s.search_vector @@ websearch_to_tsquery('{$cfg}'::regconfig, unaccent(?)) OR unaccent(s.name) ILIKE unaccent(?))", [$term, '%'.addcslashes($term, '%_\\').'%']))
                ->orWhere('products.sku', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('products as v')->whereColumn('v.parent_id', 'products.id')->whereNull('v.deleted_at')->where('v.sku', 'ilike', '%'.addcslashes($term, '%_\\').'%')));
        }

        foreach ($filter['attributes'] ?? [] as $attr) {
            $query->whereExists(fn ($e) => $e->selectRaw('1')->from('product_attribute_values as pav')
                ->join('attribute_values as av', 'av.id', '=', 'pav.attribute_value_id')->join('attributes as a', 'a.id', '=', 'av.attribute_id')
                ->whereColumn('pav.product_id', 'products.id')->whereNull('av.deleted_at')->where('a.code', $attr['code'])->whereIn('av.code', $attr['values']));
        }

        isset($filter['price_min']) && $query->whereRaw('('.self::PRICE_EXPR.') >= ?', [$filter['price_min']]);
        isset($filter['price_max']) && $query->whereRaw('('.self::PRICE_EXPR.') <= ?', [$filter['price_max']]);
    }

    private function applySort(Builder $query, string $locale, string $sort, ?string $search): void
    {
        $name = '(SELECT COALESCE((SELECT n.name FROM product_translations n WHERE n.product_id = products.id AND n.locale = ? AND n.deleted_at IS NULL), (SELECT n.name FROM product_translations n WHERE n.product_id = products.id AND n.deleted_at IS NULL ORDER BY n.locale LIMIT 1)))';

        match (true) {
            $sort === 'PRICE_ASC' => $query->orderByRaw(self::PRICE_EXPR.' ASC NULLS LAST'),
            $sort === 'PRICE_DESC' => $query->orderByRaw(self::PRICE_EXPR.' DESC NULLS LAST'),
            $sort === 'NAME' => $query->orderByRaw($name.' ASC', [$locale]),
            $sort === 'RELEVANCE' && $search !== null && trim($search) !== '' => $query->orderByRaw(
                "COALESCE((SELECT MAX(ts_rank(r.search_vector, websearch_to_tsquery('{$this->tsConfig($locale)}'::regconfig, unaccent(?)))) FROM product_translations r WHERE r.product_id = products.id AND r.deleted_at IS NULL), 0) DESC",
                [trim($search)],
            )->orderByDesc('products.published_at'),
            default => $query->orderByDesc('products.published_at'),
        };
    }

    /** @param list<string> $ids @return list<array<string, mixed>> in the order of $ids */
    private function summaries(string $locale, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $products = ProductModel::query()->with('translations')->whereKey($ids)->get()->keyBy('id');
        $variations = ProductModel::query()->whereIn('parent_id', $ids)->where('status', 'published')->get()->groupBy('parent_id');
        $firstImage = MediaModel::query()->with('translations')->where('model_type', ProductModel::class)->whereIn('model_id', $ids)
            ->where('collection_name', 'gallery')->orderBy('order_column')->get()->groupBy('model_id')->map->first();

        $out = [];
        foreach ($ids as $id) {
            /** @var ProductModel|null $p */
            $p = $products->get($id);
            if ($p === null) {
                continue;
            }
            $prices = $p->type === 'variable'
                ? $variations->get($id, collect())->map(fn ($v) => $v->price ?? $p->price)->filter(fn ($x) => $x !== null)->map(fn ($x) => (string) $x)->sort(SORT_NUMERIC)->values()
                : collect($p->price !== null ? [(string) $p->price] : []);
            $t = $this->pick($p->translations, $locale);

            $out[] = [
                'id' => $p->id, 'type' => $p->type, 'slug' => $t?->slug, 'name' => $t?->name, 'shortDescription' => $this->field($p->translations, $locale, 'short_description'),
                'priceFrom' => $prices->first(), 'priceTo' => $prices->last(),
                'sales' => $this->salesPayload($p, $prices->first()),
                'stockStatus' => $this->stockStatus($p->type === 'variable' ? $variations->get($id, collect())->all() : [$p], $p),
                'image' => ($image = $firstImage->get($id)) ? $this->mediaPayload($image, $locale) : null,
            ];
        }

        return $out;
    }

    // ----------------------------------------------------------------- detail

    /** @return array<string, mixed> */
    private function productView(ProductModel $p, string $locale): array
    {
        $t = $this->pick($p->translations, $locale);
        $inheritance = new VariationInheritance;
        $parent = ['price' => $p->price, 'weight' => $p->weight, 'dimensions' => $p->dimensions, 'barcode' => $p->barcode];

        $variations = $p->variations->map(function (ProductModel $v) use ($inheritance, $parent, $p, $locale) {
            $resolved = $inheritance->resolve($parent, ['price' => $v->price, 'weight' => $v->weight, 'dimensions' => $v->dimensions, 'barcode' => $v->barcode]);

            return [
                'id' => $v->id, 'sku' => $v->sku, 'status' => $v->status, 'price' => $resolved['values']['price'] === null ? null : (string) $resolved['values']['price'],
                'weight' => $resolved['values']['weight'] === null ? null : (string) $resolved['values']['weight'], 'dimensions' => $resolved['values']['dimensions'],
                'overridden' => $resolved['overridden'], 'valueIds' => $v->attributeValues->pluck('id')->all(),
                'stockStatus' => $this->stockStatus([$v], $p),
                // A variation shows its own photos, or the product's when it has none.
                'gallery' => ($v->gallery->isNotEmpty() ? $v->gallery : $p->gallery)->map(fn ($m) => $this->mediaPayload($m, $locale))->values()->all(),
                'rollPrice' => $p->sell_by_roll ? $this->rollPrice($p, $resolved['values']['price']) : null,
            ];
        })->values()->all();

        $usedValueIds = collect($variations)->pluck('valueIds')->flatten()->unique()->all();
        $prices = collect($variations)->pluck('price')->filter()->sort(SORT_NUMERIC)->values();
        $single = $p->type === 'variable' ? null : $p->price;

        return [
            'id' => $p->id, 'type' => $p->type, 'status' => $p->status, 'sku' => $p->sku,
            'slug' => $t?->slug, 'name' => $t?->name, 'shortDescription' => $this->field($p->translations, $locale, 'short_description'), 'description' => $this->field($p->translations, $locale, 'description'),
            'price' => $single === null ? null : (string) $single,
            'priceFrom' => $p->type === 'variable' ? $prices->first() : ($single === null ? null : (string) $single),
            'priceTo' => $p->type === 'variable' ? $prices->last() : ($single === null ? null : (string) $single),
            'weight' => $p->weight === null ? null : (string) $p->weight, 'dimensions' => $p->dimensions,
            'sales' => $this->salesPayload($p, $p->type === 'variable' ? $prices->first() : $single),
            'isCustomizable' => (bool) $p->is_customizable,
            'stockStatus' => $this->stockStatus($p->type === 'variable' ? $p->variations->all() : [$p], $p),
            'gallery' => $p->gallery->map(fn ($m) => $this->mediaPayload($m, $locale))->values()->all(),
            'categories' => $p->categories->map(fn ($c) => ['id' => $c->id, 'slug' => $this->pick($c->translations, $locale)?->slug, 'name' => $this->pick($c->translations, $locale)?->name, 'isPrimary' => (bool) $c->pivot->is_primary])->values()->all(),
            'tags' => $p->tags->map(fn ($tag) => ['id' => $tag->id, 'slug' => $this->pick($tag->translations, $locale)?->slug, 'name' => $this->pick($tag->translations, $locale)?->name])->values()->all(),
            'attributes' => $this->attributesPayload($p, $locale, $usedValueIds),
            'variations' => $variations,
            'groupItems' => $this->groupItems($p, $locale),
            'seo' => ['title' => $this->field($p->translations, $locale, 'meta_title') ?? $t?->name, 'description' => $this->field($p->translations, $locale, 'meta_description') ?? $this->field($p->translations, $locale, 'short_description'), 'canonicalSlug' => $t?->slug,
                'alternates' => $p->translations->map(fn ($x) => ['locale' => $x->locale, 'slug' => $x->slug])->values()->all()],
            'publishedAt' => $p->published_at?->toDateTimeString(),
        ];
    }

    /** @param list<string> $usedValueIds @return list<array<string, mixed>> */
    private function attributesPayload(ProductModel $p, string $locale, array $usedValueIds): array
    {
        $links = DB::table('product_attributes')->where('product_id', $p->id)->orderBy('position')->get();
        if ($links->isEmpty()) {
            return [];
        }
        $offered = DB::table('product_attribute_values')->where('product_id', $p->id)->pluck('attribute_value_id')->all();
        $attributes = AttributeModel::query()->with(['translations', 'values.translations'])->whereKey($links->pluck('attribute_id'))->get()->keyBy('id');

        return $links->map(function ($link) use ($attributes, $offered, $usedValueIds, $locale, $p) {
            $a = $attributes->get($link->attribute_id);
            if ($a === null) {
                return null;
            }
            // Options nobody can buy (no visible variation uses them) are not offered.
            $values = $a->values->filter(fn ($v) => in_array($v->id, $offered, true) && ($p->type !== 'variable' || ! $link->is_variation || in_array($v->id, $usedValueIds, true)));

            return [
                'id' => $a->id, 'code' => $a->code, 'type' => $a->type, 'name' => $this->pick($a->translations, $locale)?->name,
                'isVariation' => (bool) $link->is_variation, 'isVisible' => (bool) $link->is_visible,
                'values' => $values->map(fn ($v) => ['id' => $v->id, 'code' => $v->code, 'label' => $this->pick($v->translations, $locale)?->label, 'meta' => $v->meta])->values()->all(),
            ];
        })->filter()->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function groupItems(ProductModel $p, string $locale): array
    {
        if ($p->type !== 'grouped') {
            return [];
        }
        $rows = DB::table('product_group_items')->where('group_id', $p->id)->orderBy('position')->get();
        $members = $this->summaries($locale, $rows->pluck('product_id')->all());
        $byId = collect($members)->keyBy('id');

        return $rows->map(fn ($r) => $byId->has($r->product_id) ? ['quantity' => (string) $r->default_quantity, 'product' => $byId->get($r->product_id)] : null)->filter()->values()->all();
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function salesPayload(ProductModel $p, ?string $unitPrice): array
    {
        $s = SalesUnit::fromColumns($p->getAttributes());

        return [
            'unit' => $s->unit, 'step' => (string) $s->step, 'min' => (string) $s->min, 'max' => $s->max === null ? null : (string) $s->max,
            'sellByCut' => $s->sellByCut, 'sellByRoll' => $s->sellByRoll,
            'rollLength' => $s->rollLength === null ? null : (string) $s->rollLength,
            'rollPrice' => $s->sellByRoll ? $this->rollPrice($p, $unitPrice) : null,
        ];
    }

    private function rollPrice(ProductModel $p, mixed $unitPrice): ?string
    {
        if (! $p->sell_by_roll) {
            return null;
        }
        $s = SalesUnit::fromColumns($p->getAttributes());

        return $unitPrice === null && $s->rollPrice === null ? null : (string) $s->priceOfOneRoll($unitPrice ?? '0');
    }

    /** @param list<ProductModel> $buyables @return 'IN_STOCK'|'ON_BACKORDER'|'OUT_OF_STOCK' */
    private function stockStatus(array $buyables, ProductModel $sales): string
    {
        $status = 'OUT_OF_STOCK';

        foreach ($buyables as $item) {
            if (! $item->manage_stock || (float) $item->stock_quantity >= (float) $sales->min_quantity) {
                return 'IN_STOCK';
            }
            $item->allow_backorder && $status = 'ON_BACKORDER';
        }

        return $status;
    }

    /** @return array<string, mixed>|null */
    private function mediaPayload(?MediaModel $m, string $locale): ?array
    {
        if ($m === null) {
            return null;
        }
        $m->loadMissing('translations');
        $alt = $m->translations->firstWhere('locale', $locale)?->alt ?? $m->translations->firstWhere('locale', config('app.locale_default'))?->alt ?? $m->name;

        return ['id' => $m->id, 'url' => $m->url, 'thumbUrl' => $m->thumb_url, 'srcset' => $m->srcset, 'alt' => $alt, 'width' => $m->width, 'height' => $m->height, 'conversions' => $m->conversionList()];
    }

    /** @return array<string, mixed> */
    private function categoryNode(CategoryModel $c, string $locale): array
    {
        $t = $this->pick($c->translations, $locale);

        return ['id' => $c->id, 'slug' => $t?->slug, 'name' => $t?->name, 'depth' => (int) $c->depth];
    }

    private function chainActive(string $path): bool
    {
        return ! DB::table('categories')->whereRaw('path @> ?::ltree', [$path])->where(fn ($q) => $q->where('is_active', false)->orWhereNotNull('deleted_at'))->exists();
    }

    private function tsConfig(string $locale): string
    {
        return match (explode('-', $locale)[0]) {
            'es' => 'spanish', 'en' => 'english', default => 'simple'
        };
    }

    /** One text field: the requested language if it filled it in, otherwise the default language's. */
    private function field(Collection $translations, string $locale, string $column): ?string
    {
        foreach ([$locale, config('app.locale_default')] as $candidate) {
            $value = $translations->firstWhere('locale', $candidate)?->{$column};

            if ($value !== null && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /** The requested locale, else the default one, else anything: a missing translation never hides content. */
    private function pick(Collection $translations, string $locale): mixed
    {
        return $translations->firstWhere('locale', $locale) ?? $translations->firstWhere('locale', config('app.locale_default')) ?? $translations->first();
    }
}
