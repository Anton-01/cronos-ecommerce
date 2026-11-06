<?php

namespace Tests\Feature\Catalog;

use Database\Seeders\ClientCatalogSeeder;
use Database\Seeders\PricingSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\GraphQLTestCase;

final class ClientCatalogApiTest extends GraphQLTestCase
{
    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PricingSeeder::class, ClientCatalogSeeder::class]);
        Cache::flush();
        $this->admin = $this->adminToken();
    }

    private function ok(TestResponse $r): array
    {
        $r->assertJsonMissingPath('errors');

        return $r->json('data');
    }

    private function id(string $table, string $code): string
    {
        return (string) DB::table($table)->where('code', $code)->value('id');
    }

    public function test_a_list_is_edited_through_the_api_and_a_used_entry_cannot_be_deleted(): void
    {
        $lists = $this->ok($this->adminGql('{ adminCatalogLists { code label } }', [], $this->admin))['adminCatalogLists'];
        $this->assertSame(['packaging_type', 'unit', 'weight_unit', 'length_unit'], array_column($lists, 'code'));

        $created = $this->ok($this->adminGql('mutation { createCatalogOption(catalog: "packaging_type", code: "bolsa", labels: [{locale: "es-MX", value: "En bolsa"}, {locale: "en-US", value: "Bagged"}], position: 5) { id code labels { locale value } inUse } }', [], $this->admin))['createCatalogOption'];
        $this->assertSame(['bolsa', false], [$created['code'], $created['inUse']]);

        $this->ok($this->adminGql('mutation($id: ID!) { updateCatalogOption(id: $id, labels: [{locale: "es-MX", value: "Bolsa"}], isActive: false) { isActive labels { value } } }', ['id' => $created['id']], $this->admin));
        $active = $this->ok($this->adminGql('{ adminCatalogOptions(catalog: "packaging_type", includeInactive: false) { code } }', [], $this->admin))['adminCatalogOptions'];
        $this->assertNotContains('bolsa', array_column($active, 'code'));
        $this->ok($this->adminGql('mutation($id: ID!) { deleteCatalogOption(id: $id) }', ['id' => $created['id']], $this->admin));

        $unit = $this->ok($this->adminGql('mutation($m: JSON) { createCatalogOption(catalog: "unit", code: "caja", labels: [{locale: "es-MX", value: "caja"}], meta: $m) { meta } }', ['m' => ['sell_unit' => 'piece', 'base' => true, 'presentation' => true, 'plural' => ['es-MX' => 'cajas']]], $this->admin))['createCatalogOption'];
        $this->assertEquals(['sell_unit' => 'piece', 'base' => true, 'presentation' => true, 'roll' => false, 'plural' => ['es-MX' => 'cajas']], $unit['meta']);
    }

    public function test_only_people_who_manage_the_catalog_can_change_the_lists(): void
    {
        $support = $this->adminToken('help@example.com', 'support');
        $this->ok($this->adminGql('{ adminCatalogOptions(catalog: "unit") { code } }', [], $support));
        $r = $this->adminGql('mutation { createCatalogOption(catalog: "unit", code: "x", labels: [{locale: "es-MX", value: "x"}], meta: {sell_unit: "piece"}) { id } }', [], $support);
        $this->assertStringContainsString('nauthorized', (string) $r->json('errors.0.message'));
    }

    public function test_product_types_and_the_quick_form_through_the_api(): void
    {
        $types = $this->ok($this->adminGql('{ adminProductTypes { id code skuPrefix requiredFields variantAttributeIds names { locale value } } }', [], $this->admin))['adminProductTypes'];
        $liston = collect($types)->firstWhere('code', 'liston');
        $this->assertSame(['LIS', ['design', 'packaging_type', 'base_unit']], [$liston['skuPrefix'], $liston['requiredFields']]);

        $fields = $this->ok($this->adminGql('{ adminSpecFields { key kind scope group catalog } }', [], $this->admin))['adminSpecFields'];
        $this->assertContains('supplier_code', array_column($fields, 'key'));

        $color = $this->id('attributes', 'color');
        $width = $this->id('attributes', 'width');
        $red = DB::table('attribute_values')->where('code', 'rojo')->value('id');
        $r = $this->ok($this->adminGql('mutation($i: QuickProductInput!) { quickCreateProduct(input: $i) { productId type variations { sku valueIds } } }', ['i' => [
            'typeId' => $liston['id'], 'names' => [['locale' => 'es-MX', 'value' => 'Listón rojo']], 'price' => '12.00',
            'specs' => ['design' => 'Liso', 'packaging_type' => 'unitario', 'base_unit' => 'metro'], 'variantValues' => [$color => [$red]],
            'newValues' => [$width => [['label' => '12 mm']]],
        ]], $this->admin))['quickCreateProduct'];
        $this->assertSame(['variable', ['LIS-00001']], [$r['type'], array_column($r['variations'], 'sku')]);

        $sheet = $this->ok($this->adminGql('query($p: ID!) { adminProductSpecs(productId: $p) { typeId missing fields { key state value required } } }', ['p' => $r['productId']], $this->admin))['adminProductSpecs'];
        $this->assertSame($liston['id'], $sheet['typeId']);
        $this->assertSame([], $sheet['missing']);
        $design = collect($sheet['fields'])->firstWhere('key', 'design');
        $this->assertSame(['value', 'Liso', true], [$design['state'], $design['value'], $design['required']]);

        $this->ok($this->adminGql('mutation($p: ID!) { updateProductSpecs(productId: $p, specs: {design: null}, states: {design: "not_applicable"}) { fields { key state } } }', ['p' => $r['productId']], $this->admin));
        $sheet = $this->ok($this->adminGql('query($p: ID!) { adminProductSpecs(productId: $p) { missing fields { key state } } }', ['p' => $r['productId']], $this->admin))['adminProductSpecs'];
        $this->assertSame('not_applicable', collect($sheet['fields'])->firstWhere('key', 'design')['state']);
        $this->assertSame([], $sheet['missing'], '"does not apply" is an answer');

        $this->ok($this->adminGql('mutation($p: ID!) { updateProductSpecs(productId: $p, states: {design: "pending"}) { missing } }', ['p' => $r['productId']], $this->admin));
        $missing = $this->ok($this->adminGql('{ adminMissingData { sku name missing typeName } }', [], $this->admin))['adminMissingData'];
        $this->assertSame([['sku' => null, 'name' => 'Listón rojo', 'missing' => ['Diseño o modelo'], 'typeName' => 'Listón']], $missing);
    }
}
