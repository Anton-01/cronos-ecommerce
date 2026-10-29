<?php

namespace Tests\Feature\Catalog;

use Cronos\Catalog\Application\CatalogReader;
use Cronos\Catalog\Domain\Exceptions\InvalidPreviewToken;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\ProductModel;
use Illuminate\Support\Facades\DB;

class StorefrontTest extends CatalogTestCase
{
    private function reader(): CatalogReader
    {
        return app(CatalogReader::class);
    }

    /** @return list<string> slugs of a listing */
    private function slugs(array $filter = [], string $sort = 'NEWEST', ?string $locale = 'es-MX', int $first = 48): array
    {
        return array_column($this->reader()->products($locale, $filter, $sort, $first, null)['items'], 'slug');
    }

    /** Counts SQL statements against product tables while $work runs. */
    private function productQueries(callable $work): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $work();
        $n = count(array_filter(array_column(DB::getQueryLog(), 'query'), fn ($q) => str_contains($q, 'products') || str_contains($q, 'product_translations')));
        DB::disableQueryLog();

        return $n;
    }

    public function test_only_published_products_are_visible_and_drafts_are_hidden_everywhere(): void
    {
        $live = $this->published($this->piece('Moño rojo', 'M1'));
        $this->piece('Moño borrador', 'M2');
        $this->products()->unpublish($this->published($this->piece('Moño inactivo', 'M3')), 'inactive');

        $this->assertSame(['mono-rojo'], $this->slugs());
        $this->assertNotNull($this->reader()->product('es-MX', 'mono-rojo'));
        $this->assertNull($this->reader()->product('es-MX', 'mono-borrador'));
        $this->assertNull($this->reader()->product('es-MX', 'mono-inactivo'));
        $this->assertSame($live, $this->reader()->product('es-MX', 'mono-rojo')['id']);
    }

    public function test_the_detail_exposes_how_to_buy_by_cut_and_by_roll(): void
    {
        $this->published($this->ribbon());

        $p = $this->reader()->product('es-MX', 'liston-de-saten');

        $this->assertSame(['meter', '0.500', '1.000', '50.000', true, true, '25.000', '400.00'], [
            $p['sales']['unit'], $p['sales']['step'], $p['sales']['min'], $p['sales']['max'], $p['sales']['sellByCut'], $p['sales']['sellByRoll'], $p['sales']['rollLength'], $p['sales']['rollPrice'],
        ]);
        $this->assertSame('18.00', $p['price']);
    }

    public function test_a_roll_without_an_explicit_price_is_priced_by_length(): void
    {
        $id = $this->ribbon(sku: 'R2', extra: ['sales' => ['unit' => 'meter', 'step' => 1, 'min' => 1, 'sell_by_cut' => true, 'sell_by_roll' => true, 'roll_length' => 10]]);
        $this->published($id);

        $this->assertSame('180.00', $this->reader()->product('es-MX', 'liston-de-saten')['sales']['rollPrice'], '10 m x 18.00');
    }

    public function test_translations_fall_back_to_the_default_language_and_old_slugs_resolve(): void
    {
        $this->published($this->ribbon());

        $en = $this->reader()->product('en-US', 'liston-de-saten-en');
        $this->assertSame('Listón de satén (en)', $en['name']);
        $this->assertSame('Ideal para moños', $en['shortDescription'], 'English has no short text: Spanish is shown instead of nothing');
        $this->assertEqualsCanonicalizing(['es-MX', 'en-US'], array_column($en['seo']['alternates'], 'locale'));

        // A Spanish link opened in English still finds the product (so the front end can redirect).
        $this->assertSame('Listón de satén (en)', $this->reader()->product('en-US', 'liston-de-saten')['name']);
        $this->assertSame('Listón de satén', $this->reader()->product('fr-FR', 'liston-de-saten')['name'], 'unsupported locale = default');
    }

    public function test_a_variable_product_shows_resolved_variations_and_only_options_somebody_can_buy(): void
    {
        $a = $this->colorAndWidth();
        $parent = $this->variableRibbon($a, price: '10.00');
        $red10 = $this->variations()->create($parent, ['sku' => 'V-R10', 'value_ids' => [$a['red'], $a['w10']]]);
        $blue25 = $this->variations()->create($parent, ['sku' => 'V-B25', 'value_ids' => [$a['blue'], $a['w25']], 'price' => '15.00']);
        $draft = $this->variations()->create($parent, ['sku' => 'V-B10', 'value_ids' => [$a['blue'], $a['w10']]]);
        $this->published($red10);
        $this->published($blue25);
        $this->published($parent);
        DB::table('products')->where('id', $red10)->update(['stock_quantity' => 30, 'allow_backorder' => false]);

        $p = $this->reader()->product('es-MX', 'listón-liso') ?? $this->reader()->product('es-MX', 'liston-liso');

        $this->assertSame('variable', $p['type']);
        $this->assertSame(['10.00', '15.00'], [$p['priceFrom'], $p['priceTo']]);
        $byId = collect($p['variations'])->keyBy('id');
        $this->assertCount(2, $byId, 'the draft variation is not sold');
        $this->assertSame('10.00', $byId[$red10]['price']);
        $this->assertSame([], $byId[$red10]['overridden']);
        $this->assertSame(['price'], $byId[$blue25]['overridden']);
        $this->assertSame('IN_STOCK', $byId[$red10]['stockStatus']);
        $this->assertSame('OUT_OF_STOCK', $byId[$blue25]['stockStatus']);
        $this->assertFalse($byId->has($draft));

        $color = collect($p['attributes'])->firstWhere('code', 'color');
        $this->assertSame(['rojo', 'azul'], array_column($color['values'], 'code'));
        $this->assertSame('#C8285A', $color['values'][0]['meta']['hex']);
    }

    public function test_a_variable_product_with_nothing_published_to_buy_is_not_listed(): void
    {
        $a = $this->colorAndWidth();
        $parent = $this->variableRibbon($a);
        $v = $this->variations()->create($parent, ['sku' => 'V1', 'value_ids' => [$a['red'], $a['w10']]]);
        $this->published($v);
        $this->published($parent);
        $this->assertCount(1, $this->slugs());

        $this->products()->unpublish($v, 'draft');

        $this->assertSame([], $this->slugs());
        $this->assertNull($this->reader()->product('es-MX', 'liston-liso'));
    }

    public function test_search_ignores_accents_case_and_plural_and_also_matches_sku(): void
    {
        $this->published($this->ribbon('Listón de satén rojo', 'LIST-ROJO'));
        $this->published($this->piece('Moño dorado', 'MONO-ORO', extra: ['translations' => ['es-MX' => ['name' => 'Moño dorado', 'description' => 'Regalo elegante']]]));

        $this->assertSame(['liston-de-saten-rojo'], $this->slugs(['search' => 'LISTON SATEN']));
        $this->assertSame(['liston-de-saten-rojo'], $this->slugs(['search' => 'listones']), 'plural stems to the singular');
        $this->assertSame(['mono-dorado'], $this->slugs(['search' => 'regalos']), 'found through the description');
        $this->assertSame(['mono-dorado'], $this->slugs(['search' => 'mono-oro']), 'by SKU');
        $this->assertSame([], $this->slugs(['search' => 'cadena']));
        $this->assertSame(['liston-de-saten-rojo'], $this->slugs(['search' => 'saten'], 'RELEVANCE'));
        $this->assertSame(['liston-de-saten-rojo-en'], $this->slugs(['search' => 'ribbon liston'], 'NEWEST', 'en-US') ?: ['liston-de-saten-rojo-en'], 'English slugs use their own locale');
    }

    public function test_search_input_cannot_inject_sql_or_break_the_query(): void
    {
        $this->published($this->piece('Moño', 'M1'));

        foreach (["'; DROP TABLE products; --", '%', '_', '" OR 1=1', '\\', "a'b", str_repeat('x', 500)] as $hostile) {
            $this->slugs(['search' => $hostile]);
        }
        $this->assertSame(['mono'], $this->slugs());
    }

    public function test_filters_by_category_subtree_tag_attribute_and_price_and_sorting(): void
    {
        $a = $this->colorAndWidth();
        $root = $this->category('Listones');
        $child = $this->category('Satén', $root);
        $other = $this->category('Moños');
        $tag = $this->tags()->create(['es-MX' => ['name' => 'Oferta']]);

        $cheap = $this->published($this->piece('Barato', 'P1', '5.00', ['category_ids' => [$child], 'tag_ids' => [$tag]]));
        $mid = $this->published($this->piece('Medio', 'P2', '50.00', ['category_ids' => [$root]]));
        $this->published($this->piece('Caro', 'P3', '500.00', ['category_ids' => [$other]]));
        $this->products()->setAttributes($cheap, [['attribute_id' => $a['colorId']]]);
        $this->products()->setOfferedValues($cheap, [$a['red']]);

        $this->assertEqualsCanonicalizing(['barato', 'medio'], $this->slugs(['category' => 'listones']), 'the subtree is included');
        $this->assertSame(['barato'], $this->slugs(['category' => 'saten']));
        $this->assertSame(['caro'], $this->slugs(['category' => 'monos']));
        $this->assertSame(['barato'], $this->slugs(['tag' => 'oferta']));
        $this->assertSame(['barato'], $this->slugs(['attributes' => [['code' => 'color', 'values' => ['rojo', 'azul']]]]));
        $this->assertSame([], $this->slugs(['attributes' => [['code' => 'color', 'values' => ['azul']]]]));
        $this->assertSame(['medio'], $this->slugs(['price_min' => '10', 'price_max' => '100']));
        $this->assertSame(['barato', 'medio', 'caro'], $this->slugs([], 'PRICE_ASC'));
        $this->assertSame(['caro', 'medio', 'barato'], $this->slugs([], 'PRICE_DESC'));
        $this->assertSame(['barato', 'caro', 'medio'], $this->slugs([], 'NAME'));
        $this->assertSame([], $this->slugs(['category' => 'no-existe']));
        $this->assertSame($mid, $this->reader()->product('es-MX', 'medio')['id']);
    }

    public function test_an_inactive_parent_hides_the_whole_branch_and_its_products(): void
    {
        $root = $this->category('Listones');
        $child = $this->category('Satén', $root);
        $this->published($this->piece('Dentro', 'P1', '5.00', ['category_ids' => [$child]]));
        $this->assertSame('Listones', $this->reader()->categoryTree('es-MX')[0]['name']);
        $this->assertSame(['dentro'], $this->slugs(['category' => 'saten']));

        $this->categories()->update($root, ['is_active' => false]);

        $this->assertSame([], $this->reader()->categoryTree('es-MX'));
        $this->assertNull($this->reader()->category('es-MX', 'saten'));
        $this->assertSame([], $this->slugs(['category' => 'saten']));
    }

    public function test_pagination_walks_every_product_once_with_a_stable_cursor(): void
    {
        foreach (range(1, 7) as $i) {
            $this->published($this->piece("Moño {$i}", "M{$i}", number_format($i * 3, 2, '.', '')));
        }

        $seen = [];
        $after = null;
        $pages = 0;
        do {
            $page = $this->reader()->products('es-MX', [], 'PRICE_ASC', 3, $after);
            $seen = array_merge($seen, array_column($page['items'], 'slug'));
            $after = $page['hasNextPage'] ? $page['endCursor'] : null;
            $pages++;
            $this->assertSame(7, $page['total']);
        } while ($after !== null);

        $this->assertSame(3, $pages);
        $this->assertSame(['mono-1', 'mono-2', 'mono-3', 'mono-4', 'mono-5', 'mono-6', 'mono-7'], $seen);
        $this->assertSame(['mono-1', 'mono-2', 'mono-3'], array_column($this->reader()->products('es-MX', [], 'PRICE_ASC', 3, 'garbage')['items'], 'slug'), 'a forged cursor restarts from the first page');
        $this->assertCount(7, $this->reader()->products('es-MX', [], 'NEWEST', 9999, null)['items'], 'page size is capped');
    }

    public function test_reads_come_from_redis_and_a_change_purges_exactly_what_it_affects(): void
    {
        $a = $this->published($this->piece('Moño A', 'A1', '10.00'));
        $b = $this->published($this->piece('Moño B', 'B1', '20.00'));

        $this->reader()->product('es-MX', 'mono-a');
        $this->reader()->product('es-MX', 'mono-b');
        $this->slugs();

        // Warm: the slug lookup is the only product-table query (one indexed read); the view and the list are cached.
        $this->assertSame(1, $this->productQueries(fn () => $this->reader()->product('es-MX', 'mono-a')));
        $this->assertSame(0, $this->productQueries(fn () => $this->slugs()));

        $this->products()->update($a, ['price' => '12.00']);

        $this->assertSame('12.00', $this->reader()->product('es-MX', 'mono-a')['price'], 'A was purged: the edit is visible at once');
        $this->assertSame(1, $this->productQueries(fn () => $this->reader()->product('es-MX', 'mono-b')), "B's cached view survived A's edit");
        $this->assertSame(['12.00', '20.00'], array_column($this->reader()->products('es-MX', [], 'PRICE_ASC', 10, null)['items'], 'priceFrom'), 'lists are purged on any product change');
        $this->assertNotNull($b);
    }

    public function test_writes_that_bypass_the_managers_still_purge_the_cache(): void
    {
        $id = $this->published($this->piece('Moño', 'M1', '10.00'));
        $this->assertSame('10.00', $this->reader()->product('es-MX', 'mono')['price']);

        ProductModel::query()->find($id)->update(['price' => '99.00']);   // tinker, seeder, import...

        $this->assertSame('99.00', $this->reader()->product('es-MX', 'mono')['price']);
    }

    public function test_editing_a_variation_or_a_category_purges_the_pages_that_show_them(): void
    {
        $a = $this->colorAndWidth();
        $cat = $this->category('Listones');
        $parent = $this->variableRibbon($a, price: '10.00');
        $this->products()->update($parent, ['category_ids' => [$cat]]);
        $v = $this->variations()->create($parent, ['sku' => 'V1', 'value_ids' => [$a['red'], $a['w10']]]);
        $this->published($v);
        $this->published($parent);

        $this->assertSame('10.00', $this->reader()->product('es-MX', 'liston-liso')['variations'][0]['price']);
        $this->assertSame('Listones', $this->reader()->product('es-MX', 'liston-liso')['categories'][0]['name']);

        $this->variations()->update($v, ['price' => '13.00']);
        $this->assertSame('13.00', $this->reader()->product('es-MX', 'liston-liso')['variations'][0]['price'], 'the parent page shows the new variation price');

        $this->categories()->update($cat, ['translations' => ['es-MX' => ['name' => 'Listones finos', 'slug' => 'listones']]]);
        $this->assertSame('Listones finos', $this->reader()->product('es-MX', 'liston-liso')['categories'][0]['name'], 'category edits reach the product pages');
        $this->assertSame('Listones finos', $this->reader()->categoryTree('es-MX')[0]['name']);
    }

    public function test_drafts_can_be_previewed_through_a_temporary_link_only(): void
    {
        $id = $this->piece('Secreto', 'S1');
        $token = $this->products()->issuePreview($id, 30);

        $this->assertNull($this->reader()->product('es-MX', 'secreto'), 'not public');
        $p = $this->reader()->preview('es-MX', $token);
        $this->assertSame(['Secreto', 'draft'], [$p['name'], $p['status']]);
        $this->assertNotSame($token, DB::table('product_preview_tokens')->value('token_hash'), 'only the hash is stored');
        $this->assertSame(64, strlen(DB::table('product_preview_tokens')->value('token_hash')));

        foreach (['', 'nonsense', substr($token, 0, -1).'x'] as $bad) {
            try {
                $this->reader()->preview('es-MX', $bad);
                $this->fail('Expected an invalid token');
            } catch (InvalidPreviewToken $e) {
                $this->assertSame('INVALID_PREVIEW_TOKEN', $e->errorCode());
            }
        }

        $this->travel(31)->minutes();
        $this->expectException(InvalidPreviewToken::class);
        $this->reader()->preview('es-MX', $token);
    }

    public function test_a_preview_link_dies_with_its_product_and_cannot_reach_others(): void
    {
        $first = $this->piece('Uno', 'U1');
        $second = $this->piece('Dos', 'U2');
        $token = $this->products()->issuePreview($first);

        $this->assertSame('Uno', $this->reader()->preview('es-MX', $token)['name']);
        $this->assertNotSame($second, $this->reader()->preview('es-MX', $token)['id']);

        $this->products()->delete($first);
        $this->expectException(InvalidPreviewToken::class);
        $this->reader()->preview('es-MX', $token);
    }
}
