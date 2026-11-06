<?php

namespace Tests\Feature\Catalog;

use Cronos\Catalog\Application\ProductSpecs;
use Cronos\Catalog\Application\QuickProductCreator;
use Cronos\Catalog\Domain\Exceptions\InvalidProduct;
use Cronos\Inventory\Domain\ValueObjects\StockCode;
use Database\Seeders\ClientCatalogSeeder;
use Illuminate\Support\Facades\DB;

final class QuickProductTest extends CatalogTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClientCatalogSeeder::class);
    }

    private function id(string $table, string $code): string
    {
        return (string) DB::table($table)->where('code', $code)->value('id');
    }

    private function value(string $attribute, string $code): string
    {
        return (string) DB::table('attribute_values')->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')->where(['attributes.code' => $attribute, 'attribute_values.code' => $code])->value('attribute_values.id');
    }

    /** @return array<string, mixed> */
    private function form(array $over = []): array
    {
        return $over + [
            'type_id' => $this->id('product_types', 'liston'), 'names' => ['es-MX' => 'Listón de satén', 'en-US' => 'Satin ribbon'], 'price' => '18.00',
            'specs' => ['design' => 'Liso', 'packaging_type' => 'unitario', 'base_unit' => 'metro', 'presentation_unit' => 'rollo', 'units_per_presentation' => '25', 'uses' => ['moños']],
            'variant_values' => [
                $this->id('attributes', 'color') => [$this->value('color', 'rojo'), $this->value('color', 'azul')],
                $this->id('attributes', 'width') => [$this->value('width', '10_mm'), $this->value('width', '25_mm')],
                $this->id('attributes', 'finish') => [$this->value('finish', 'brillante')],
            ],
            'product_values' => [$this->id('attributes', 'material') => [$this->value('material', 'satin')]],
        ];
    }

    public function test_one_form_creates_the_product_every_combination_and_their_skus_and_codes(): void
    {
        $r = app(QuickProductCreator::class)->create($this->form(), null);

        $this->assertSame('variable', $r['type']);
        $this->assertCount(4, $r['variations']);
        $this->assertSame(['LIS-00001', 'LIS-00002', 'LIS-00003', 'LIS-00004'], array_column($r['variations'], 'sku'));
        $this->assertNull(DB::table('products')->where('id', $r['product_id'])->value('sku'), 'the parent has no SKU: its variations do');

        foreach ($r['variations'] as $v) {
            $this->assertTrue(StockCode::isValid((string) DB::table('products')->where('id', $v['id'])->value('stock_code')), 'every variant is born with its warehouse code');
        }
        $product = DB::table('products')->where('id', $r['product_id'])->first();
        $this->assertSame(['meter', '25.000', 1], [$product->sell_unit, $product->roll_length, (int) $product->sell_by_roll]);
        $this->assertSame('draft', $product->status);

        $sheet = app(ProductSpecs::class)->get($r['product_id']);
        $this->assertSame(['value', 'value', 'pending'], [$sheet['states']['design'], $sheet['states']['base_unit'], $sheet['states']['weight']]);
        $this->assertSame([], $sheet['missing']);
        $this->assertSame(1, DB::table('product_attribute_values')->where('product_id', $r['product_id'])->where('attribute_value_id', $this->value('material', 'satin'))->count());
    }

    public function test_values_typed_in_the_form_are_added_to_their_lists_once(): void
    {
        $color = $this->id('attributes', 'color');
        $width = $this->id('attributes', 'width');
        $input = $this->form(['variant_values' => [$color => [$this->value('color', 'rojo')]], 'new_values' => [$color => [['label' => 'Verde olivo', 'hex' => '#556B2F']], $width => [['label' => '30 mm']]]]);
        $creator = app(QuickProductCreator::class);
        $first = $creator->create($input, null);
        $creator->create($input + [], null); // the same typed values again: reused, not duplicated

        $this->assertCount(2, $first['variations']);
        $this->assertSame(1, DB::table('attribute_values')->where('code', 'verde_olivo')->count());
        $this->assertSame(1, DB::table('attribute_values')->where('code', '30_mm')->count());
        $this->assertSame('#556B2F', json_decode(DB::table('attribute_values')->where('code', 'verde_olivo')->value('meta'), true)['hex']);
    }

    public function test_a_type_without_picked_variants_gives_one_simple_product_with_its_own_sku(): void
    {
        $r = app(QuickProductCreator::class)->create(['type_id' => $this->id('product_types', 'pieza_decorativa'), 'names' => ['es-MX' => 'Moño navideño'], 'price' => '25.00', 'specs' => ['base_unit' => 'pieza']], null);

        $this->assertSame(['simple', 'PZA-00001', []], [$r['type'], $r['sku'], $r['variations']]);
        $this->assertSame('piece', DB::table('products')->where('id', $r['product_id'])->value('sell_unit'));
        $this->assertNotNull(DB::table('products')->where('id', $r['product_id'])->value('stock_code'));
    }

    public function test_details_of_one_combination_apply_to_that_variation_only(): void
    {
        $red = $this->value('color', 'rojo');
        $w = $this->value('width', '25_mm');
        $r = app(QuickProductCreator::class)->create($this->form([
            'variant_values' => [$this->id('attributes', 'color') => [$red, $this->value('color', 'azul')], $this->id('attributes', 'width') => [$w], $this->id('attributes', 'finish') => [$this->value('finish', 'mate')]],
            'variants' => [['value_ids' => [$red, $w, $this->value('finish', 'mate')], 'price' => '21.50', 'supplier_code' => 'PROV-88', 'notes' => 'Lote 3']],
        ]), null);

        $special = collect($r['variations'])->first(fn ($v) => in_array($red, $v['value_ids'], true));
        $other = collect($r['variations'])->first(fn ($v) => ! in_array($red, $v['value_ids'], true));
        $this->assertSame('21.50', (string) DB::table('products')->where('id', $special['id'])->value('price'));
        $this->assertNull(DB::table('products')->where('id', $other['id'])->value('price'));
        $this->assertSame('PROV-88', app(ProductSpecs::class)->get($special['id'])['specs']['supplier_code']);
        $this->assertArrayNotHasKey('supplier_code', app(ProductSpecs::class)->get($other['id'])['specs']);
    }

    public function test_the_same_name_twice_gets_its_own_slug_instead_of_failing(): void
    {
        $creator = app(QuickProductCreator::class);
        $creator->create($this->form(['variant_values' => []]), null);
        $creator->create($this->form(['variant_values' => []]), null);

        $this->assertSame(2, DB::table('product_translations')->where('locale', 'es-MX')->distinct()->count('slug'));
    }

    public function test_a_mistake_anywhere_leaves_nothing_behind(): void
    {
        $before = [DB::table('products')->count(), DB::table('sku_counters')->count(), DB::table('product_specs')->count()];
        try {
            app(QuickProductCreator::class)->create($this->form(['specs' => ['packaging_type' => 'inventado']]), null);
            $this->fail('Bad packaging');
        } catch (InvalidProduct) {
            $this->assertSame($before, [DB::table('products')->count(), DB::table('sku_counters')->count(), DB::table('product_specs')->count()]);
        }
    }

    public function test_only_the_attributes_of_the_type_can_be_used(): void
    {
        $this->expectException(InvalidProduct::class);
        app(QuickProductCreator::class)->create($this->form(['type_id' => $this->id('product_types', 'pieza_decorativa')]), null); // widths are not variant attributes of a piece
    }

    public function test_an_axis_that_was_not_picked_simply_does_not_define_the_variants(): void
    {
        $r = app(QuickProductCreator::class)->create($this->form(['variant_values' => [$this->id('attributes', 'color') => [$this->value('color', 'rojo')], $this->id('attributes', 'width') => [$this->value('width', '25_mm'), $this->value('width', '10_mm')]]]), null);

        $this->assertCount(2, $r['variations'], 'one colour x two widths; no finish axis');
        $axes = DB::table('product_attributes')->where('product_id', $r['product_id'])->where('is_variation', true)->count();
        $this->assertSame(2, $axes);
    }
}
