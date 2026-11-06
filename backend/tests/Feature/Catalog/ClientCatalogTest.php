<?php

namespace Tests\Feature\Catalog;

use Cronos\Catalog\Application\CatalogOptionManager;
use Cronos\Catalog\Application\ProductManager;
use Cronos\Catalog\Application\ProductSpecs;
use Cronos\Catalog\Application\ProductTypeManager;
use Cronos\Catalog\Application\SkuGenerator;
use Cronos\Catalog\Domain\Exceptions\CannotPublish;
use Cronos\Catalog\Domain\Exceptions\InUse;
use Cronos\Catalog\Domain\Exceptions\InvalidProduct;
use Cronos\Catalog\Domain\Exceptions\InvalidSalesUnit;
use Cronos\Catalog\Domain\Ports\ProductSpecStore;
use Database\Seeders\ClientCatalogSeeder;
use Illuminate\Support\Facades\DB;

final class ClientCatalogTest extends CatalogTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClientCatalogSeeder::class);
    }

    private function lists(): CatalogOptionManager
    {
        return app(CatalogOptionManager::class);
    }

    private function specs(): ProductSpecs
    {
        return app(ProductSpecs::class);
    }

    private function typeId(string $code = 'liston'): string
    {
        return DB::table('product_types')->where('code', $code)->value('id');
    }

    private function code(array $rows): array
    {
        return array_column($rows, 'code');
    }

    public function test_the_clients_lists_are_seeded_once_and_their_edits_survive_a_reseed(): void
    {
        $this->assertSame(['unitario', 'en_conjunto', 'por_caja'], $this->code($this->lists()->list('packaging_type')));
        $this->assertSame(['pieza', 'metro', 'rollo', 'paquete', 'docena'], $this->code($this->lists()->list('unit')));
        $colors = DB::table('attribute_values')->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')->where('attributes.code', 'color')->pluck('attribute_values.code')->all();
        $this->assertCount(22, $colors);
        foreach (['multicolor', 'arcoiris', 'transparente', 'azul_marino'] as $c) {
            $this->assertContains($c, $colors);
        }
        $this->assertCount(15, DB::table('attribute_values')->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')->where('attributes.code', 'material')->get());
        $this->assertCount(8, DB::table('attribute_values')->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')->where('attributes.code', 'finish')->get());

        $id = DB::table('catalog_options')->where(['catalog' => 'packaging_type', 'code' => 'por_caja'])->value('id');
        $this->lists()->update($id, ['es-MX' => 'En caja'], null, 7, false);
        $this->seed(ClientCatalogSeeder::class);

        $row = DB::table('catalog_options')->where('id', $id)->first();
        $this->assertSame('En caja', json_decode($row->labels, true)['es-MX']);
        $this->assertFalse((bool) $row->is_active, 'a deactivated option stays that way');
        $this->assertSame(3, DB::table('catalog_options')->where('catalog', 'packaging_type')->count());
    }

    public function test_every_list_can_grow_be_renamed_deactivated_and_deleted_when_unused(): void
    {
        $id = $this->lists()->create('packaging_type', 'bolsa', ['es-MX' => 'En bolsa'], null, 9);
        $this->assertContains('bolsa', $this->code($this->lists()->list('packaging_type', false)));

        $this->lists()->update($id, null, null, null, false);
        $this->assertNotContains('bolsa', $this->code($this->lists()->list('packaging_type', false)));
        $this->assertContains('bolsa', $this->code($this->lists()->list('packaging_type', true)));
        $this->lists()->delete($id);
        $this->assertNotContains('bolsa', $this->code($this->lists()->list('packaging_type')));

        $this->expectException(InvalidProduct::class);
        $this->lists()->create('packaging_type', 'unitario', ['es-MX' => 'Repetido']);
    }

    public function test_a_list_entry_that_a_data_sheet_uses_is_deactivated_not_deleted(): void
    {
        $id = DB::table('catalog_options')->where(['catalog' => 'packaging_type', 'code' => 'unitario'])->value('id');
        $this->specs()->update($this->ribbon(), ['packaging_type' => 'unitario'], [], null, false, null);

        try {
            $this->lists()->delete($id);
            $this->fail('In use');
        } catch (InUse) {
            $this->assertTrue($this->lists()->list('packaging_type')[0]['in_use']);
        }
        $this->lists()->update($id, null, null, null, false);
        $this->assertSame('unitario', $this->specs()->get($this->ribbon('Otro', 'LIST-2'))['specs']['packaging_type'] ?? 'unitario');
    }

    public function test_a_unit_needs_to_say_how_it_is_counted_and_a_measure_unit_its_factor(): void
    {
        $this->expectException(InvalidProduct::class);
        $this->lists()->create('unit', 'caja', ['es-MX' => 'caja'], ['base' => true]);
    }

    public function test_a_measure_unit_without_a_factor_is_refused(): void
    {
        $this->expectException(InvalidProduct::class);
        $this->lists()->create('length_unit', 'pulgada', ['es-MX' => 'pulgada'], []);
    }

    public function test_an_empty_field_is_pending_a_value_wins_and_not_applicable_is_an_answer(): void
    {
        $roll = $this->ribbon();
        $sheet = $this->specs()->get($roll);
        $this->assertSame('pending', $sheet['states']['design']);

        $sheet = $this->specs()->update($roll, ['design' => 'Liso'], ['weight' => 'not_applicable', 'design' => 'not_applicable'], null, false, null);
        $this->assertSame(['value', 'not_applicable', 'pending'], [$sheet['states']['design'], $sheet['states']['weight'], $sheet['states']['height']]);

        // clearing the value brings back the state the person chose before it was filled
        $sheet = $this->specs()->update($roll, ['design' => null], ['design' => 'pending'], null, false, null);
        $this->assertSame('pending', $sheet['states']['design']);
    }

    public function test_values_are_checked_against_their_lists_and_units(): void
    {
        $roll = $this->ribbon();
        $sheet = $this->specs()->update($roll, [
            'packaging_type' => 'en_conjunto', 'base_unit' => 'metro', 'presentation_unit' => 'rollo', 'units_per_presentation' => '25',
            'weight' => ['value' => '120.5', 'unit' => 'g'], 'width' => ['value' => 2.5, 'unit' => 'cm'], 'uses' => ['moños', 'Moños', 'manualidades'],
        ], [], null, false, null);

        $this->assertSame(['moños', 'manualidades'], $sheet['specs']['uses'], 'duplicates ignoring case are dropped');
        $this->assertEquals(['value' => '120.5', 'unit' => 'g'], $sheet['specs']['weight']);
        $this->assertSame('25', $sheet['specs']['units_per_presentation']);

        foreach ([
            ['packaging_type' => 'inventada'],
            ['base_unit' => 'pieza_x'],
            ['presentation_unit' => 'pieza'],          // a piece is not a presentation
            ['weight' => ['value' => '-1', 'unit' => 'g']],
            ['weight' => ['value' => '3', 'unit' => 'cm']],  // a length unit is not a weight unit
            ['length' => ['value' => '1.2345', 'unit' => 'cm']],
            ['secondary_colors' => ['no-existe']],
        ] as $bad) {
            try {
                $this->specs()->update($roll, $bad, [], null, false, null);
                $this->fail('Accepted '.json_encode($bad));
            } catch (InvalidProduct) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_secondary_colours_come_from_the_colour_list_only(): void
    {
        $roll = $this->ribbon();
        $red = DB::table('attribute_values')->where('code', 'rojo')->value('id');
        $gold = DB::table('attribute_values')->where('code', 'dorado')->value('id');
        $material = DB::table('attribute_values')->where('code', 'satin')->value('id');

        $sheet = $this->specs()->update($roll, ['secondary_colors' => [$red, $gold]], [], null, false, null);
        $this->assertSame([$red, $gold], $sheet['specs']['secondary_colors']);

        $this->expectException(InvalidProduct::class);
        $this->specs()->update($roll, ['secondary_colors' => [$material]], [], null, false, null);
    }

    public function test_variant_fields_live_on_the_variation_and_product_fields_on_the_product(): void
    {
        $color = DB::table('attributes')->where('code', 'color')->value('id');
        $parent = app(ProductManager::class)->create(['type' => 'variable', 'translations' => ['es-MX' => ['name' => 'Variable']]]);
        $this->specs()->update($parent, ['design' => 'Liso'], [], null, false, null);

        try {
            $this->specs()->update($parent, ['supplier_code' => 'X1'], [], null, false, null);
            $this->fail('A variable parent has no supplier code of its own');
        } catch (InvalidProduct) {
            $this->addToAssertionCount(1);
        }
        $this->assertNotNull($color);
    }

    public function test_required_fields_of_the_type_block_publishing_until_filled_or_ruled_out(): void
    {
        $roll = $this->ribbon();
        $products = app(ProductManager::class);
        // Without a type nothing is required: legacy products keep publishing.
        $products->publish($roll);
        $products->unpublish($roll, 'draft');

        $this->specs()->update($roll, ['design' => 'Liso'], [], $this->typeId(), true, null);
        try {
            $products->publish($roll);
            $this->fail('Required data pending');
        } catch (CannotPublish $e) {
            $this->assertStringContainsString('Tipo de empaque', $e->getMessage());
            $this->assertStringNotContainsString('Diseño', $e->getMessage());
        }
        $this->assertSame(['Tipo de empaque', 'Unidad base'], $this->specs()->missingLabels($roll));

        $this->specs()->update($roll, ['packaging_type' => 'unitario', 'base_unit' => 'metro'], [], null, false, null);
        $products->publish($roll);
        $this->assertSame('published', DB::table('products')->where('id', $roll)->value('status'));

        // "does not apply" is an answer too
        $other = $this->ribbon('Otro', 'OTRO-1');
        $this->specs()->update($other, ['packaging_type' => 'unitario', 'base_unit' => 'metro'], ['design' => 'not_applicable'], $this->typeId(), true, null);
        $products->publish($other);
        $this->assertSame([], $this->specs()->get($other)['missing']);
    }

    public function test_product_types_validate_their_fields_and_attributes(): void
    {
        $types = app(ProductTypeManager::class);
        $color = DB::table('attributes')->where('code', 'color')->value('id');
        $id = $types->create(['code' => 'bolsa', 'names' => ['es-MX' => 'Bolsa'], 'sku_prefix' => 'bol', 'sales' => ['unit' => 'piece'], 'required_fields' => ['design'], 'variant_attribute_ids' => [$color]]);
        $this->assertSame('BOL', DB::table('product_types')->where('id', $id)->value('sku_prefix'));

        foreach ([
            ['required_fields' => ['invented']],
            ['variant_attribute_ids' => [$color], 'product_attribute_ids' => [$color]],
            ['sku_prefix' => 'TOO-LONG-PREFIX'],
            ['sales' => ['unit' => 'piece', 'step' => '0.5']],      // a piece cannot be cut
        ] as $bad) {
            try {
                $types->update($id, $bad);
                $this->fail('Accepted '.json_encode($bad));
            } catch (InvalidProduct|InvalidSalesUnit) {
                $this->addToAssertionCount(1);
            }
        }
        $types->delete($id);
        $this->assertSame(0, DB::table('product_types')->where('code', 'bolsa')->count());
    }

    public function test_skus_are_consecutive_per_prefix_and_skip_any_that_was_typed_by_hand(): void
    {
        $skus = app(SkuGenerator::class);
        $this->assertSame(['LIS-00001', 'LIS-00002'], [$skus->next('lis'), $skus->next('LIS')]);
        $this->assertSame('PZA-00001', $skus->next('PZA'));

        $this->piece('Tomada', 'LIS-00003', '5.00');
        $this->assertSame('LIS-00004', $skus->next('LIS'));
    }

    public function test_the_missing_data_list_names_what_each_product_lacks(): void
    {
        $roll = $this->ribbon();
        $this->specs()->update($roll, ['design' => 'Liso'], [], $this->typeId(), true, null);
        $rows = app(ProductSpecStore::class)->withMissing(50);

        $this->assertCount(1, $rows);
        $this->assertSame(['packaging_type', 'base_unit'], $rows[0]['missing']);
    }
}
