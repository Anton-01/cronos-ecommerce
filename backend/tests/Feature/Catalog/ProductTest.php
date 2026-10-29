<?php

namespace Tests\Feature\Catalog;

use Cronos\Catalog\Domain\Exceptions\CannotPublish;
use Cronos\Catalog\Domain\Exceptions\InUse;
use Cronos\Catalog\Domain\Exceptions\InvalidProduct;
use Cronos\Catalog\Domain\Exceptions\InvalidSalesUnit;
use Cronos\Catalog\Domain\Exceptions\InvalidVariation;
use Cronos\Catalog\Domain\Exceptions\NotFound;
use Cronos\Catalog\Domain\Exceptions\SkuTaken;
use Cronos\Catalog\Domain\Exceptions\SlugTaken;
use Cronos\Catalog\Domain\Services\VariationInheritance;
use Cronos\Catalog\Domain\ValueObjects\SalesUnit;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\ProductModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ProductTest extends CatalogTestCase
{
    private function row(string $id): object
    {
        return DB::table('products')->where('id', $id)->first();
    }

    public function test_a_ribbon_is_sold_by_free_cut_and_by_whole_roll_with_exact_decimals(): void
    {
        $id = $this->ribbon();
        $row = $this->row($id);

        $this->assertSame(['meter', '0.500', '1.000', '50.000', true, true, '25.000', '400.00'], [
            $row->sell_unit, $row->quantity_step, $row->min_quantity, $row->max_quantity, (bool) $row->sell_by_cut, (bool) $row->sell_by_roll, $row->roll_length, $row->roll_price,
        ]);

        $sales = SalesUnit::fromColumns((array) $row);
        $sales->assertValidCut('2.5');
        $this->assertSame('45.00', (string) $sales->priceOfCut($row->price, '2.5'));
        $this->assertSame('800.00', (string) $sales->priceOfRolls($row->price, 2), 'two rolls at the roll price');
        $this->assertSame('50.000', (string) $sales->metersForRolls(2), 'stock leaves in metres (decimal scale kept)');
    }

    public function test_impossible_sales_configurations_never_reach_the_database(): void
    {
        foreach ([
            ['unit' => 'meter', 'step' => '0.5', 'min' => 1, 'sell_by_roll' => true],              // roll without a length
            ['unit' => 'meter', 'sell_by_cut' => false, 'sell_by_roll' => false],                 // neither mode
            ['unit' => 'meter', 'step' => '0.5', 'min' => '1.2'],                                 // min off the step grid
            ['unit' => 'piece', 'step' => '0.5', 'min' => '0.5'],                                 // fractional pieces
        ] as $sales) {
            try {
                $this->products()->create(['type' => 'simple', 'sales' => $sales, 'translations' => ['es-MX' => ['name' => 'x'.uniqid()]]]);
                $this->fail('Expected InvalidSalesUnit for '.json_encode($sales));
            } catch (InvalidSalesUnit $e) {
                $this->assertSame('INVALID_SALES_UNIT', $e->errorCode());
            }
        }
        $this->assertSame(0, DB::table('products')->count());
    }

    public function test_the_database_itself_rejects_what_the_domain_would_reject(): void
    {
        $id = $this->piece();

        $this->expectException(QueryException::class);
        DB::table('products')->where('id', $id)->update(['sell_unit' => 'meter', 'sell_by_cut' => false, 'sell_by_roll' => false]);
    }

    public function test_sku_and_slug_are_unique_and_a_deleted_product_frees_them(): void
    {
        $first = $this->piece('Moño', 'MONO-1');

        foreach ([
            fn () => $this->piece('Otro', 'mono-1'),     // SKU is case-insensitive
            fn () => $this->piece('Moño', 'MONO-2'),     // slug derived from the same name
        ] as $i => $attempt) {
            try {
                $attempt();
                $this->fail('Expected a uniqueness error');
            } catch (SkuTaken|SlugTaken $e) {
                $this->assertSame($i === 0 ? 'SKU_TAKEN' : 'SLUG_TAKEN', $e->errorCode());
            }
        }

        $this->products()->delete($first);
        $this->assertNotEmpty($this->piece('Moño', 'MONO-1'));
    }

    public function test_invalid_input_is_rejected_early(): void
    {
        foreach ([
            ['type' => 'variation'],
            ['type' => 'simple', 'translations' => []],
            ['type' => 'simple', 'translations' => ['fr-FR' => ['name' => 'x']]],
            ['type' => 'simple', 'translations' => ['es-MX' => ['name' => 'a']], 'price' => '-5'],
            ['type' => 'simple', 'translations' => ['es-MX' => ['name' => 'b']], 'price' => '10.999'],
            ['type' => 'simple', 'translations' => ['es-MX' => ['name' => 'c']], 'sku' => '../etc'],
            ['type' => 'simple', 'translations' => ['en-US' => ['name' => 'only english']]],
            ['type' => 'simple', 'translations' => ['es-MX' => ['name' => 'd']], 'dimensions' => ['unit' => 'km']],
        ] as $input) {
            try {
                $this->products()->create($input);
                $this->fail('Expected InvalidProduct for '.json_encode($input));
            } catch (InvalidProduct $e) {
                $this->assertSame('INVALID_PRODUCT', $e->errorCode());
            }
        }
    }

    public function test_publishing_explains_everything_that_is_missing_and_a_published_product_stays_valid(): void
    {
        $draft = $this->products()->create(['type' => 'simple', 'translations' => ['es-MX' => ['name' => 'Incompleto']]]);

        try {
            $this->products()->publish($draft);
            $this->fail('Expected CannotPublish');
        } catch (CannotPublish $e) {
            $this->assertStringContainsString('SKU', $e->getMessage());
            $this->assertStringContainsString('price', $e->getMessage());
        }

        $this->products()->update($draft, ['sku' => 'OK-1', 'price' => '9.90']);
        $this->products()->publish($draft);
        $this->assertSame(['published', true], [$this->row($draft)->status, $this->row($draft)->published_at !== null]);

        // Editing a live product must not leave it without a price.
        $this->expectException(CannotPublish::class);
        $this->products()->update($draft, ['price' => null]);
    }

    public function test_unpublishing_hides_the_product_without_losing_when_it_first_went_live(): void
    {
        $id = $this->published($this->piece());
        $first = $this->row($id)->published_at;

        $this->products()->unpublish($id, 'inactive');
        $this->assertSame('inactive', $this->row($id)->status);
        $this->products()->publish($id);
        $this->assertSame($first, $this->row($id)->published_at, 'the first publication date is kept');
    }

    public function test_variations_inherit_from_the_parent_and_override_individually(): void
    {
        $a = $this->colorAndWidth();
        $parent = $this->variableRibbon($a, price: '10.00');
        $this->products()->update($parent, ['weight' => '0.020']);

        $red10 = $this->variations()->create($parent, ['sku' => 'LISO-ROJO-10', 'value_ids' => [$a['red'], $a['w10']]]);
        $blue25 = $this->variations()->create($parent, ['sku' => 'LISO-AZUL-25', 'value_ids' => [$a['w25'], $a['blue']], 'price' => '14.50']);

        $this->assertNull($this->row($red10)->price, 'nothing stored = inherit');
        $this->assertSame('14.50', $this->row($blue25)->price);

        $resolved = (new VariationInheritance)->resolve(
            ['price' => $this->row($parent)->price, 'weight' => $this->row($parent)->weight],
            ['price' => $this->row($red10)->price, 'weight' => $this->row($red10)->weight],
        );
        $this->assertSame(['10.00', '0.020', []], [$resolved['values']['price'], $resolved['values']['weight'], $resolved['overridden']]);

        $this->variations()->update($red10, ['price' => '11.00']);
        $this->assertSame('11.00', $this->row($red10)->price);
        $this->variations()->update($red10, ['price' => null]);
        $this->assertNull($this->row($red10)->price, 'null goes back to inheriting');
        $this->assertSame('LISO-ROJO-10', $this->row($red10)->sku, 'untouched fields stay as they were');
    }

    public function test_a_variation_must_pick_exactly_one_offered_value_per_attribute_and_each_combination_is_unique(): void
    {
        $a = $this->colorAndWidth();
        $parent = $this->variableRibbon($a);
        $this->variations()->create($parent, ['sku' => 'V1', 'value_ids' => [$a['red'], $a['w10']]]);

        $bad = [
            'duplicate combination' => ['V2', [$a['w10'], $a['red']]],
            'missing an attribute' => ['V3', [$a['red']]],
            'two values of one attribute' => ['V4', [$a['red'], $a['blue'], $a['w10']]],
            'unknown value' => ['V5', [$a['red'], str_repeat('0', 26)]],
        ];
        foreach ($bad as $label => [$sku, $values]) {
            try {
                $this->variations()->create($parent, ['sku' => $sku, 'value_ids' => $values]);
                $this->fail("Expected InvalidVariation: {$label}");
            } catch (InvalidVariation $e) {
                $this->assertSame('INVALID_VARIATION', $e->errorCode(), $label);
            }
        }

        $this->expectException(SkuTaken::class);
        $this->variations()->create($parent, ['sku' => 'v1', 'value_ids' => [$a['blue'], $a['w25']]]);
    }

    public function test_generating_variations_creates_each_missing_combination_once_with_readable_skus(): void
    {
        $a = $this->colorAndWidth();
        $parent = $this->variableRibbon($a);
        $this->variations()->create($parent, ['sku' => 'MANUAL', 'value_ids' => [$a['red'], $a['w10']]]);

        $created = $this->variations()->generate($parent);

        $this->assertCount(3, $created, '2 colours x 2 widths = 4, one already existed');
        $this->assertSame([], $this->variations()->generate($parent), 'running it again creates nothing');
        $skus = DB::table('products')->where('parent_id', $parent)->pluck('sku')->all();
        $this->assertCount(4, array_unique($skus));
        $this->assertContains('LISO-AZUL-25MM', $skus);
        $this->assertSame(['draft'], DB::table('products')->where('parent_id', $parent)->pluck('status')->unique()->all());
    }

    public function test_variation_attributes_and_offered_values_are_protected_once_variations_exist(): void
    {
        $a = $this->colorAndWidth();
        $parent = $this->variableRibbon($a);
        $this->variations()->create($parent, ['sku' => 'V1', 'value_ids' => [$a['red'], $a['w10']]]);

        foreach ([
            fn () => $this->products()->setOfferedValues($parent, [$a['blue'], $a['w10'], $a['w25']]),                                   // withdraws "red", used
            fn () => $this->products()->setAttributes($parent, [['attribute_id' => $a['colorId'], 'is_variation' => true]]),             // drops "width"
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected InUse');
            } catch (InUse) {
                $this->addToAssertionCount(1);
            }
        }

        $this->products()->setOfferedValues($parent, [$a['red'], $a['blue'], $a['w10']]);   // withdrawing an unused value is fine
        $this->assertSame(3, DB::table('product_attribute_values')->where('product_id', $parent)->count());
    }

    public function test_a_variable_product_needs_a_published_variation_and_deleting_it_removes_its_variations(): void
    {
        $a = $this->colorAndWidth();
        $parent = $this->variableRibbon($a);
        $variation = $this->variations()->create($parent, ['sku' => 'V1', 'value_ids' => [$a['red'], $a['w10']]]);

        try {
            $this->products()->publish($parent);
            $this->fail('Expected CannotPublish');
        } catch (CannotPublish $e) {
            $this->assertStringContainsString('variation', $e->getMessage());
        }

        $this->products()->publish($variation);   // inherits the parent's price
        $this->products()->publish($parent);
        $this->assertSame('published', $this->row($parent)->status);

        $this->products()->delete($parent);
        $this->assertSoftDeleted('products', ['id' => $variation]);
        $this->assertNotNull($this->row($variation)->deleted_at);
        $this->assertSame(0, DB::table('product_translations')->where('product_id', $parent)->whereNull('deleted_at')->count());
    }

    public function test_grouped_products_bundle_simple_or_variable_products_only(): void
    {
        $group = $this->products()->create(['type' => 'grouped', 'price' => '99', 'translations' => ['es-MX' => ['name' => 'Kit regalo']]]);
        $this->assertNull($this->row($group)->price, 'a group has no price of its own');
        $ribbon = $this->ribbon();
        $bow = $this->piece();

        $this->products()->setGroupItems($group, [['product_id' => $ribbon, 'default_quantity' => '2.5'], ['product_id' => $bow]]);
        $this->assertSame(['2.500', '1.000'], DB::table('product_group_items')->where('group_id', $group)->orderBy('position')->pluck('default_quantity')->all());

        foreach ([[$group], [str_repeat('0', 26)]] as [$bad]) {
            try {
                $this->products()->setGroupItems($group, [['product_id' => $bad]]);
                $this->fail('Expected a rejection');
            } catch (InvalidProduct|NotFound) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidProduct::class);
        $this->products()->setGroupItems($ribbon, [['product_id' => $bow]]);
    }

    public function test_changes_are_audited_but_stock_bookkeeping_is_not(): void
    {
        $id = $this->piece();
        $this->products()->update($id, ['price' => '30.00']);
        DB::table('products')->where('id', $id)->update(['stock_quantity' => 5]);
        // The inventory ledger (Phase 5) writes stock without audit noise: one ledger row per movement is the record.
        ProductModel::withoutAuditing(fn () => ProductModel::query()->find($id)->update(['stock_quantity' => 7]));

        $audits = DB::table('audits')->where('auditable_id', $id)->get();
        $this->assertTrue($audits->contains('event', 'created'));
        $updates = $audits->where('event', 'updated');
        $this->assertCount(1, $updates, 'the price edit is audited; the stock write is not');
        $this->assertSame(['price' => '30.00'], json_decode($updates->first()->new_values, true));
        $this->assertStringNotContainsString('stock_quantity', $audits->pluck('new_values')->implode(' '), 'excluded from every audit row, including the creation snapshot');
    }
}
