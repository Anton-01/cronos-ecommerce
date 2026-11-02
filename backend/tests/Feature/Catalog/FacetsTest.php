<?php

namespace Tests\Feature\Catalog;

use Cronos\Catalog\Application\CatalogReader;
use Cronos\Shared\Infrastructure\Storefront\HttpStorefrontCache;
use Cronos\Shared\Infrastructure\Storefront\RevalidateStorefrontJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Orders\OrdersTestCase;

final class FacetsTest extends OrdersTestCase
{
    /** @var array{colorId: string, widthId: string, red: string, blue: string, w10: string, w25: string} */
    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->colorAndWidth();
    }

    /** A published simple product with one value of each attribute. */
    private function tagged(string $name, string $sku, string $price, string $color, string $width): string
    {
        $id = $this->piece($name, $sku, $price);
        $this->products()->setAttributes($id, [['attribute_id' => $this->a['colorId']], ['attribute_id' => $this->a['widthId']]]);
        $this->products()->setOfferedValues($id, [$this->a[$color], $this->a[$width]]);

        return $this->published($id);
    }

    private function facets(array $filter = []): array
    {
        return app(CatalogReader::class)->facets('es-MX', $filter);
    }

    private function counts(array $facets, string $code): array
    {
        $attr = collect($facets['attributes'])->firstWhere('code', $code);

        return collect($attr['values'])->mapWithKeys(fn ($v) => [$v['code'] => $v['count']])->all();
    }

    public function test_each_value_says_how_many_products_it_leaves_and_labels_and_swatches_come_with_it(): void
    {
        $this->tagged('A', 'A', '10.00', 'red', 'w10');
        $this->tagged('B', 'B', '20.00', 'red', 'w25');
        $this->tagged('C', 'C', '30.00', 'blue', 'w25');

        $f = $this->facets();
        $this->assertSame(3, $f['total']);
        $this->assertSame(['rojo' => 2, 'azul' => 1], $this->counts($f, 'color'));
        $this->assertSame(['10mm' => 1, '25mm' => 2], $this->counts($f, 'width'));
        $red = collect(collect($f['attributes'])->firstWhere('code', 'color')['values'])->firstWhere('code', 'rojo');
        $this->assertSame(['Rojo', ['hex' => '#C8285A']], [$red['label'], $red['meta']]);
    }

    public function test_choosing_a_value_narrows_the_other_attributes_but_never_hides_its_own_siblings(): void
    {
        $this->tagged('A', 'A', '10.00', 'red', 'w10');
        $this->tagged('B', 'B', '20.00', 'red', 'w25');
        $this->tagged('C', 'C', '30.00', 'blue', 'w25');

        $f = $this->facets(['attributes' => [['code' => 'color', 'values' => ['rojo']]]]);
        $this->assertSame(2, $f['total']);
        $this->assertSame(['10mm' => 1, '25mm' => 1], $this->counts($f, 'width'), 'widths are counted among the red ones');
        $this->assertSame(['rojo' => 2, 'azul' => 1], $this->counts($f, 'color'), 'blue stays on offer: the colour is counted with the colour filter off');
        $this->assertTrue(collect(collect($f['attributes'])->firstWhere('code', 'color')['values'])->firstWhere('code', 'rojo')['selected']);
    }

    public function test_an_attribute_that_is_not_filterable_or_has_no_products_is_not_offered(): void
    {
        $this->tagged('A', 'A', '10.00', 'red', 'w10');
        DB::table('attributes')->where('id', $this->a['widthId'])->update(['is_filterable' => false]);
        Cache::flush();

        $codes = array_column($this->facets()['attributes'], 'code');
        $this->assertSame(['color'], $codes);
    }

    public function test_the_price_range_ignores_the_price_filter_so_the_slider_keeps_its_ends(): void
    {
        $this->tagged('A', 'A', '10.40', 'red', 'w10');
        $this->tagged('B', 'B', '99.10', 'red', 'w25');

        $all = $this->facets()['price'];
        $this->assertSame(['10.40', '99.10'], [(float) $all['min'] === 10.4 ? '10.40' : $all['min'], (float) $all['max'] === 99.1 ? '99.10' : $all['max']]);
        $narrow = $this->facets(['price_min' => '50', 'price_max' => '60']);
        $this->assertSame(0, $narrow['total']);
        $this->assertEquals(10.4, $narrow['price']['min'], 'the range still spans the whole listing');
    }

    public function test_prices_are_asked_and_answered_in_the_currency_of_the_store(): void
    {
        $this->tagged('A', 'A', '100.00', 'red', 'w10');

        // US store: 1 MXN = 0.055 USD (seeded rate) → the product costs 5.50 USD. A "5 to 6 USD" filter must find it; "0 to 5" must not.
        $q = fn (array $vars) => $this->gql('query($m: String, $min: String, $max: String) { products(market: $m, priceMin: $min, priceMax: $max) { total } productFacets(market: $m) { price { min max currency } } }', $vars);
        $this->assertSame(1, $q(['m' => 'US', 'min' => '5', 'max' => '6'])->json('data.products.total'));
        $this->assertSame(0, $q(['m' => 'US', 'min' => '0', 'max' => '5'])->json('data.products.total'));
        $this->assertSame(['5', '6', 'USD'], array_values($q(['m' => 'US', 'min' => null, 'max' => null])->json('data.productFacets.price')), 'rounded outwards: 5.5 → 5 … 6');
        $this->assertSame(['100', '100', 'MXN'], array_values($q(['m' => 'MX', 'min' => null, 'max' => null])->json('data.productFacets.price')));
    }

    public function test_best_selling_orders_by_how_many_orders_contained_the_product(): void
    {
        $slow = $this->stocked($this->published($this->piece('Lento', 'SLOW', '10.00')), 50);
        $hit = $this->stocked($this->published($this->piece('Éxito', 'HIT', '10.00')), 50);
        $none = $this->stocked($this->published($this->piece('Sin ventas', 'NONE', '10.00')), 50);
        $this->placeOrder($hit, '1', null, 'k1');
        $this->placeOrder($hit, '5', null, 'k2');
        $this->placeOrder($slow, '9', null, 'k3'); // more units in one order, but fewer orders

        $slugs = array_column(app(CatalogReader::class)->products('es-MX', [], 'BEST_SELLING', 10, null)['items'], 'slug');
        $this->assertSame(['exito', 'lento', 'sin-ventas'], $slugs);
        $this->assertNotNull($none);
    }

    public function test_a_burst_of_changes_becomes_one_notice_to_the_storefront(): void
    {
        config(['storefront.revalidate_secret' => 's3cret']);
        Queue::fake();
        $cache = new HttpStorefrontCache;

        foreach (range(1, 5) as $i) {
            $cache->invalidate('products');
        }
        $cache->invalidate('products', 'categories');

        Queue::assertPushed(RevalidateStorefrontJob::class, 2);
        Queue::assertPushed(RevalidateStorefrontJob::class, fn (RevalidateStorefrontJob $j) => $j->tags === ['products']);
        Queue::assertPushed(RevalidateStorefrontJob::class, fn (RevalidateStorefrontJob $j) => $j->tags === ['categories'], 'the second call only adds the tag not yet announced');
    }
}
