<?php

namespace Tests\Feature\Inventory;

use Cronos\Catalog\Application\ProductManager;
use Database\Seeders\PricingSeeder;
use Database\Seeders\WarehousesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\GraphQLTestCase;

final class InventoryApiTest extends GraphQLTestCase
{
    use RefreshDatabase;

    private string $token;

    private int $mx;

    private int $us;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PricingSeeder::class, WarehousesSeeder::class]);
        Cache::flush();
        DB::table('geo_ip_ranges')->insert(['ip_from' => '187.190.0.0', 'ip_to' => '187.191.255.255', 'country_code' => 'MX', 'timezone' => null, 'region' => null]);
        $this->token = $this->adminToken();
        $this->mx = (int) DB::table('warehouses')->where('code', 'MX-01')->value('id');
        $this->us = (int) DB::table('warehouses')->where('code', 'US-01')->value('id');
    }

    private function product(string $name = 'Listón', string $sku = 'LIS', array $extra = []): string
    {
        return app(ProductManager::class)->create(['type' => 'simple', 'sku' => $sku, 'price' => '18.00', 'translations' => ['es-MX' => ['name' => $name]], ...$extra]);
    }

    private function record(string $product, string $type, string $qty, array $more = [], ?string $token = null): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '187.190.10.20'])->adminGql(
            'mutation($i: RecordMovementInput!) { recordMovement(input: $i) }',
            ['i' => ['productId' => $product, 'warehouseId' => $this->mx, 'type' => $type, 'quantity' => $qty] + $more],
            $token ?? $this->token,
        );
    }

    public function test_a_person_records_a_purchase_and_the_kardex_shows_who_where_and_when(): void
    {
        $id = $this->product();
        $ulid = $this->record($id, 'PURCHASE', '25.5', ['unitCost' => '4.20', 'note' => 'Compra a proveedor'])->assertJsonMissingPath('errors')->json('data.recordMovement');

        $k = $this->adminGql('query($p: ID) { adminKardex(productId: $p) { nextBefore entries { id type quantity balanceAfter unitCost note sku productName warehouseCode userEmail ip country timezone createdAt createdAtUtc referenceType } } }', ['p' => $id], $this->token)
            ->assertJsonMissingPath('errors')->json('data.adminKardex');

        $e = $k['entries'][0];
        $this->assertSame([$ulid, 'PURCHASE', '25.500', '25.500', '4.2000', 'MX-01', 'boss@example.com', '187.190.10.20', 'MX', 'manual', 'LIS', 'Listón'],
            [$e['id'], $e['type'], $e['quantity'], $e['balanceAfter'], $e['unitCost'], $e['warehouseCode'], $e['userEmail'], $e['ip'], $e['country'], $e['referenceType'], $e['sku'], $e['productName']]);
        $this->assertSame('America/Mexico_City', $e['timezone'], 'the range has no timezone of its own: the country default applies');
        $this->assertStringEndsWith('Z', $e['createdAtUtc']);
        $this->assertNull($k['nextBefore']);
    }

    public function test_the_stock_screen_lists_totals_per_warehouse_and_flags_low_stock(): void
    {
        $roll = $this->product('Listón rojo', 'ROJO', ['low_stock_threshold' => '10']);
        $bow = $this->product('Moño', 'MONO');
        $this->record($roll, 'PURCHASE', '40');
        $this->record($bow, 'PURCHASE', '3');
        $this->record($roll, 'WASTE', '32');

        $q = 'query($low: Boolean, $s: String) { adminStock(lowOnly: $low, search: $s) { total rows { sku name onHand reserved available low lowStockThreshold warehouses { warehouseCode onHand } } } }';
        $all = $this->adminGql($q, ['low' => false], $this->token)->assertJsonMissingPath('errors')->json('data.adminStock');
        $this->assertSame(2, $all['total']);
        $rojo = collect($all['rows'])->firstWhere('sku', 'ROJO');
        $this->assertSame(['8.000', '0.000', '8.000', true, [['warehouseCode' => 'MX-01', 'onHand' => '8.000']]], [$rojo['onHand'], $rojo['reserved'], $rojo['available'], $rojo['low'], $rojo['warehouses']]);

        $low = $this->adminGql($q, ['low' => true], $this->token)->json('data.adminStock.rows');
        $this->assertSame(['ROJO'], array_column($low, 'sku'));
        $this->assertSame(['ROJO'], array_column($this->adminGql('{ adminLowStock { sku } }', [], $this->token)->json('data.adminLowStock'), 'sku'));
        $this->assertSame(['MONO'], array_column($this->adminGql($q, ['s' => 'moñ'], $this->token)->json('data.adminStock.rows'), 'sku'));
    }

    public function test_errors_come_back_with_codes_the_screen_can_word(): void
    {
        $id = $this->product();
        $this->record($id, 'PURCHASE', '5');

        $this->record($id, 'WASTE', '6')->assertJsonPath('errors.0.extensions.code', 'INSUFFICIENT_STOCK');
        $this->record($id, 'ADJUSTMENT', '1')->assertJsonPath('errors.0.extensions.code', 'DIRECTION_REQUIRED');
        $this->record($id, 'PURCHASE', '1.5555')->assertJsonPath('errors.0.extensions.code', 'QUANTITY_PRECISION');
        $this->record($id, 'PURCHASE', '-3')->assertJsonPath('errors.0.extensions.code', 'INVALID_QUANTITY');
        $this->record($id, 'ADJUSTMENT', '2', ['increase' => false])->assertJsonMissingPath('errors');
    }

    public function test_count_transfer_and_compensation_through_the_api(): void
    {
        $id = $this->product();
        $first = $this->record($id, 'PURCHASE', '50')->json('data.recordMovement');

        $count = $this->adminGql('mutation($p: ID!, $w: ID!) { setStockCount(productId: $p, warehouseId: $w, counted: "48", note: "Conteo") }', ['p' => $id, 'w' => $this->mx], $this->token)->assertJsonMissingPath('errors')->json('data.setStockCount');
        $this->assertNotNull($count);
        $same = $this->adminGql('mutation($p: ID!, $w: ID!) { setStockCount(productId: $p, warehouseId: $w, counted: "48") }', ['p' => $id, 'w' => $this->mx], $this->token)->json('data.setStockCount');
        $this->assertNull($same);

        $t = $this->adminGql('mutation($p: ID!, $a: ID!, $b: ID!) { transferStock(productId: $p, fromWarehouseId: $a, toWarehouseId: $b, quantity: "8") { outId inId } }', ['p' => $id, 'a' => $this->mx, 'b' => $this->us], $this->token)
            ->assertJsonMissingPath('errors')->json('data.transferStock');
        $this->assertNotSame($t['outId'], $t['inId']);

        $this->adminGql('mutation($i: ID!) { compensateMovement(id: $i, reason: "Error de captura") }', ['i' => $t['inId']], $this->token)->assertJsonMissingPath('errors');
        $this->adminGql('mutation($i: ID!) { compensateMovement(id: $i, reason: "otra vez") }', ['i' => $t['inId']], $this->token)->assertJsonPath('errors.0.extensions.code', 'ALREADY_COMPENSATED');
        $marked = collect($this->adminGql('query($p: ID) { adminKardex(productId: $p) { entries { id compensatedBy } } }', ['p' => $id], $this->token)->json('data.adminKardex.entries'))->keyBy('id');
        $this->assertNotNull($marked[$t['inId']]['compensatedBy'], 'the undone movement says who undid it');
        $this->assertNull($marked[$t['outId']]['compensatedBy']);
        $this->adminGql('mutation($i: ID!) { compensateMovement(id: $i, reason: "x") }', ['i' => 'nope'], $this->token)->assertJsonPath('errors.0.extensions.code', 'NOT_FOUND');

        $level = fn (int $w) => (string) DB::table('stock_levels')->where(['product_id' => $id, 'warehouse_id' => $w])->value('on_hand');
        $this->assertSame(['40.000', '0.000'], [$level($this->mx), $level($this->us)], '48 counted, 8 sent to the US, then the US leg was undone (the 8 left the US books but never came back to Mexico)');
        $this->assertNotNull($first);
        $this->adminGql('{ adminInventoryHealth { ok brokenChains levelMismatches } }', [], $this->token)->assertJsonPath('data.adminInventoryHealth.ok', true);
    }

    public function test_warehouses_are_managed_from_the_back_office(): void
    {
        $create = 'mutation($i: WarehouseInput!) { createWarehouse(input: $i) { id code marketCode isDefault isActive } }';
        $w = $this->adminGql($create, ['i' => ['code' => 'tj-01', 'name' => 'Tijuana', 'marketCode' => 'mx', 'address' => ['city' => 'Tijuana']]], $this->token)->assertJsonMissingPath('errors')->json('data.createWarehouse');
        $this->assertSame(['TJ-01', 'MX', false, true], [$w['code'], $w['marketCode'], $w['isDefault'], $w['isActive']]);

        $this->adminGql($create, ['i' => ['code' => 'TJ-01', 'name' => 'dup']], $this->token)->assertJsonPath('errors.0.extensions.code', 'WAREHOUSE_EXISTS');
        $this->adminGql($create, ['i' => ['code' => 'X1', 'name' => 'x', 'marketCode' => 'ZZ']], $this->token)->assertJsonPath('errors.0.extensions.code', 'NOT_FOUND');
        $this->adminGql('mutation($id: ID!) { deleteWarehouse(id: $id) }', ['id' => $this->mx], $this->token)->assertJsonPath('errors.0.extensions.code', 'DEFAULT_WAREHOUSE');
        $this->adminGql('mutation($id: ID!) { deleteWarehouse(id: $id) }', ['id' => $w['id']], $this->token)->assertJsonPath('data.deleteWarehouse', true);
        $this->assertCount(2, $this->adminGql('{ adminWarehouses { code } }', [], $this->token)->json('data.adminWarehouses'));
    }

    public function test_support_can_look_at_stock_but_cannot_change_it(): void
    {
        $support = $this->adminToken('help@example.com', 'support');
        $id = $this->product();

        $this->adminGql('{ adminStock { total } adminKardex { nextBefore } }', [], $support)->assertJsonMissingPath('errors');
        $this->record($id, 'PURCHASE', '1', [], $support)->assertJsonPath('errors.0.message', 'This action is unauthorized.');
        $this->adminGql('{ adminInventoryHealth { ok } }', [], $support)->assertJsonPath('errors.0.message', 'This action is unauthorized.');
    }
}
