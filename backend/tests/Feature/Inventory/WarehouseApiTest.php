<?php

namespace Tests\Feature\Inventory;

use Cronos\Catalog\Application\ProductManager;
use Cronos\Inventory\Application\Actor;
use Cronos\Inventory\Application\LocationManager;
use Cronos\Inventory\Application\ReservationService;
use Cronos\Inventory\Domain\ValueObjects\StockCode;
use Database\Seeders\PricingSeeder;
use Database\Seeders\WarehousesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\GraphQLTestCase;

final class WarehouseApiTest extends GraphQLTestCase
{
    private string $operator;

    private string $lead;

    private string $admin;

    private int $mx;

    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PricingSeeder::class, WarehousesSeeder::class]);
        Cache::flush();
        DB::table('geo_ip_ranges')->insert(['ip_from' => '187.190.0.0', 'ip_to' => '187.191.255.255', 'country_code' => 'MX', 'timezone' => null, 'region' => null]);
        $this->admin = $this->adminToken('boss@example.com', 'admin');
        $this->operator = $this->adminToken('worker@example.com', 'warehouse');
        $this->lead = $this->adminToken('lead@example.com', 'warehouse-lead');
        $this->mx = (int) DB::table('warehouses')->where('code', 'MX-01')->value('id');
        $this->productId = app(ProductManager::class)->create(['type' => 'simple', 'sku' => 'LIS-1', 'price' => '18.00', 'translations' => ['es-MX' => ['name' => 'Listón rojo']]]);
    }

    private function api(string $query, array $vars, string $token): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '187.190.10.20'])->adminGql($query, $vars, $token);
    }

    private function denied(TestResponse $r, string $what = ''): void
    {
        $this->assertTrue(str_contains((string) $r->json('errors.0.message'), 'nauthorized') || str_contains((string) $r->json('errors.0.message'), 'Unauthenticated'), "{$what} should be refused, got: ".json_encode($r->json('errors.0.message') ?? $r->json('data')));
    }

    private function ok(TestResponse $r): array
    {
        $r->assertJsonMissingPath('errors');

        return $r->json('data');
    }

    private function shelf(string $code, ?string $parent = null): string
    {
        return $this->ok($this->api('mutation($w: ID!, $i: WhLocationInput!) { whCreateLocation(warehouseId: $w, input: $i) { id code depth } }', ['w' => $this->mx, 'i' => ['code' => $code, 'name' => "Shelf {$code}", 'parentId' => $parent]], $this->lead))['whCreateLocation']['id'];
    }

    private function receive(string $location, string $qty, ?string $key = null): string
    {
        return $this->ok($this->api('mutation($i: WhReceiveInput!) { whReceive(input: $i) }', ['i' => ['productId' => $this->productId, 'warehouseId' => $this->mx, 'quantity' => $qty, 'locationId' => $location, 'idempotencyKey' => $key ?? uniqid('k')]], $this->operator))['whReceive'];
    }

    public function test_the_warehouse_roles_reach_the_warehouse_and_nothing_else(): void
    {
        // The operator can work...
        $this->ok($this->api('{ whWarehouses { code } }', [], $this->operator));
        $this->ok($this->api('{ adminMe { email } }', [], $this->operator));

        // ...but the rest of the back office is refused by the server, not just hidden in the menu.
        foreach (['{ adminStock { total } }', '{ adminOrders { total } }', '{ adminKardex { nextBefore } }', '{ adminWarehouses { code } }'] as $query) {
            $this->denied($this->api($query, [], $this->operator), $query);
        }
        // Operator vs lead inside the warehouse section.
        $this->denied($this->api('{ whTrail { nextBefore } }', [], $this->operator));
        $this->denied($this->api('mutation { whOpenCount(warehouseId: 1, name: "x") }', [], $this->operator));
        $this->denied($this->api('{ whAlertProcesses { code } }', [], $this->operator));
        $this->ok($this->api('{ whTrail { nextBefore } }', [], $this->lead));
        $this->denied($this->api('{ adminOrders { total } }', [], $this->lead), 'the lead is not an order manager either');

        // A shopper gets nothing at all.
        $this->makeUser('shopper@example.com', 'customer');
        $this->denied($this->adminGql('{ whWarehouses { code } }', [], null), 'anonymous');
        $this->ok($this->api('{ whWarehouses { code } }', [], $this->admin));
    }

    public function test_the_operator_cannot_manage_shelves_policies_or_alert_recipients(): void
    {
        $this->denied($this->api('mutation($w: ID!, $i: WhLocationInput!) { whCreateLocation(warehouseId: $w, input: $i) { id } }', ['w' => $this->mx, 'i' => ['code' => 'X', 'name' => 'X']], $this->operator));
        $this->denied($this->api('mutation { whUpdatePolicies(input: {approvalUnits: 50}) { approvalUnits } }', [], $this->operator));
        $this->denied($this->api('mutation($u: ID!) { whAddSubscriber(process: "stock_low", userId: $u) { code } }', ['u' => '0'], $this->operator));
    }

    public function test_receive_scan_move_and_pick_through_the_api_with_who_and_where_on_every_row(): void
    {
        $a = $this->shelf('BOD');
        $b = $this->shelf('A', $a);
        $c = $this->shelf('E03', $b);
        $this->receive($c, '40', 'rcv-1');
        $this->receive($c, '40', 'rcv-1'); // double tap

        $code = DB::table('products')->where('id', $this->productId)->value('stock_code');
        $scan = $this->ok($this->api('query($c: String!) { whScan(code: $c) { sku name status removed stockCodeFormatted pendingToPick places { code quantity version warehouseCode } books { onHand available } } }', ['c' => "https://shop.test/w/{$code}"], $this->operator))['whScan'];
        $this->assertSame(['LIS-1', 'Listón rojo', 'draft', false], [$scan['sku'], $scan['name'], $scan['status'], $scan['removed']]);
        $this->assertSame([['code' => 'E03', 'quantity' => '40.000', 'version' => 1, 'warehouseCode' => 'MX-01']], $scan['places']);
        $this->assertSame('40.000', $scan['books'][0]['onHand']);
        $this->assertSame(substr($code, 0, 10).'-'.substr($code, 10), $scan['stockCodeFormatted']);

        $this->ok($this->api('mutation($i: WhMoveInput!) { whMove(input: $i) { outId inId } }', ['i' => ['productId' => $this->productId, 'fromLocationId' => $c, 'toLocationId' => $a, 'quantity' => '5.5', 'expectedVersion' => 1, 'idempotencyKey' => 'mv-1']], $this->operator));
        $stale = $this->api('mutation($i: WhMoveInput!) { whMove(input: $i) { outId } }', ['i' => ['productId' => $this->productId, 'fromLocationId' => $c, 'toLocationId' => $a, 'quantity' => '1', 'expectedVersion' => 1, 'idempotencyKey' => 'mv-2']], $this->operator);
        $this->assertSame('STALE_VERSION', $this->errorCode($stale));

        $trail = $this->ok($this->api('query($p: ID) { whTrail(productId: $p, first: 10) { entries { type quantity balanceBefore balanceAfter locationCode counterpartCode userEmail userName ip country } } }', ['p' => $this->productId], $this->lead))['whTrail']['entries'];
        $this->assertSame(['move_in', 'move_out', 'receive'], array_map('strtolower', array_column($trail, 'type')));
        $out = $trail[1];
        $this->assertSame(['-5.500', '40.000', '34.500', 'E03', 'BOD', 'worker@example.com', '187.190.10.20', 'MX'], [$out['quantity'], $out['balanceBefore'], $out['balanceAfter'], $out['locationCode'], $out['counterpartCode'], $out['userEmail'], $out['ip'], $out['country']]);
    }

    public function test_the_qr_reads_a_product_whatever_its_state_and_stock(): void
    {
        $code = DB::table('products')->where('id', $this->productId)->value('stock_code');
        app(ProductManager::class)->unpublish($this->productId);
        $scan = $this->ok($this->api('query($c: String!) { whScan(code: $c) { status places { code } books { onHand } pendingToPick } }', ['c' => $code], $this->operator))['whScan'];
        $this->assertSame(['inactive', [], [], '0.000'], [$scan['status'], $scan['places'], $scan['books'], $scan['pendingToPick']]);

        $this->assertSame('INVALID_STOCK_CODE', $this->errorCode($this->api('{ whScan(code: "not a code") { sku } }', [], $this->operator)));
        $unknown = StockCode::generate()->value;
        $this->assertNull($this->ok($this->api('query($c: String!) { whScan(code: $c) { sku } }', ['c' => $unknown], $this->operator))['whScan']);
    }

    public function test_a_big_adjustment_waits_for_the_lead_and_a_count_goes_through_review(): void
    {
        $a = $this->shelf('A');
        $this->receive($a, '50');
        $version = (int) DB::table('location_stock')->where('location_id', $a)->value('version');
        $req = $this->ok($this->api('mutation($i: WhAdjustmentInput!) { whRequestAdjustment(input: $i) { id status } }', ['i' => ['productId' => $this->productId, 'locationId' => $a, 'targetQuantity' => '30', 'seenVersion' => $version, 'reason' => 'Roll cut', 'idempotencyKey' => 'adj-1']], $this->operator))['whRequestAdjustment'];
        $this->assertSame('pending', $req['status']);

        $this->denied($this->api('mutation($i: ID!) { whApproveAdjustment(id: $i) }', ['i' => $req['id']], $this->operator));
        $list = $this->ok($this->api('{ whAdjustmentRequests(status: "pending") { id delta requestedByName status } }', [], $this->operator))['whAdjustmentRequests'];
        $this->assertSame([['id' => $req['id'], 'delta' => '-20.000', 'requestedByName' => 'worker@example.com', 'status' => 'pending']], $list);
        $this->ok($this->api('mutation($i: ID!) { whApproveAdjustment(id: $i, note: "ok") }', ['i' => $req['id']], $this->lead));
        $this->assertSame('30.000', (string) DB::table('location_stock')->where('location_id', $a)->value('quantity'));

        $session = $this->ok($this->api('mutation($w: ID!) { whOpenCount(warehouseId: $w, name: "Weekly") }', ['w' => $this->mx], $this->lead))['whOpenCount'];
        $count = fn (string $n, string $token, string $key) => $this->ok($this->api('mutation($i: WhCountInput!) { whSubmitCount(input: $i) { id status } }', ['i' => ['sessionId' => $session, 'productId' => $this->productId, 'locationId' => $a, 'counted' => $n, 'idempotencyKey' => $key]], $token))['whSubmitCount'];
        $this->assertSame('confirmed', $count('29', $this->operator, 'c1')['status']);
        $second = $count('25', $this->lead, 'c2');
        $this->assertSame('in_review', $second['status']);
        $this->denied($this->api('mutation($e: ID!) { whReviewCount(entryId: $e, approve: true) }', ['e' => $second['id']], $this->operator));
        $this->ok($this->api('mutation($e: ID!) { whReviewCount(entryId: $e, approve: true, note: "recounted") }', ['e' => $second['id']], $this->lead));
        $this->ok($this->api('mutation($s: ID!) { whCloseCount(sessionId: $s) }', ['s' => $session], $this->lead));

        $this->assertSame('25.000', (string) DB::table('location_stock')->where('location_id', $a)->value('quantity'));
        $entries = $this->ok($this->api('query($s: ID!) { whCountEntries(sessionId: $s) { status counted systemAtStart countedByName reviewedByName } }', ['s' => $session], $this->lead))['whCountEntries'];
        $this->assertSame(['confirmed', 'confirmed'], array_column($entries, 'status'));
    }

    public function test_the_lead_manages_policies_and_who_hears_each_process(): void
    {
        $this->ok($this->api('mutation { whUpdatePolicies(input: {approvalUnits: 0, approvalPercent: 20, approvalAmount: 500}) { approvalUnits approvalPercent approvalAmount } }', [], $this->lead));
        $this->assertSame('POLICY_WOULD_DISABLE_APPROVAL', $this->errorCode($this->api('mutation { whUpdatePolicies(input: {approvalPercent: 0, approvalAmount: 0}) { approvalUnits } }', [], $this->lead)));
        $history = $this->ok($this->api('{ whPolicyHistory { key value changedByName } }', [], $this->lead))['whPolicyHistory'];
        $this->assertContains(['key' => 'approval_percent', 'value' => '20', 'changedByName' => 'lead@example.com'], $history);

        $worker = DB::table('users')->where('email', 'worker@example.com')->value('id');
        $shopper = $this->makeUser('shopper@example.com', 'customer')->id;
        $processes = $this->ok($this->api('mutation($u: ID!) { whAddSubscriber(process: "stock_low", userId: $u, email: false) { code subscribers { email inbox emailEnabled } } }', ['u' => $worker], $this->lead))['whAddSubscriber'];
        $low = collect($processes)->firstWhere('code', 'stock_low');
        $this->assertSame([['email' => 'worker@example.com', 'inbox' => true, 'emailEnabled' => false]], $low['subscribers']);
        $this->assertSame('NOT_A_SYSTEM_USER', $this->errorCode($this->api('mutation($u: ID!) { whAddSubscriber(process: "stock_low", userId: $u) { code } }', ['u' => $shopper], $this->lead)));
        $candidates = array_column($this->ok($this->api('{ whAlertCandidates { email } }', [], $this->lead))['whAlertCandidates'], 'email');
        $this->assertNotContains('shopper@example.com', $candidates);
        $this->assertSame('added', $this->ok($this->api('{ whSubscriptionLog { action userName } }', [], $this->lead))['whSubscriptionLog'][0]['action']);
    }

    public function test_the_inbox_of_each_person_is_private_and_can_be_marked_read(): void
    {
        $a = $this->shelf('A');
        $worker = DB::table('users')->where('email', 'worker@example.com')->value('id');
        $this->ok($this->api('mutation($u: ID!) { whAddSubscriber(process: "stock_out", userId: $u) { code } }', ['u' => $worker], $this->lead));
        $this->receive($a, '5');
        $this->ok($this->api('mutation($p: ID!, $w: ID!) { whSetThreshold(productId: $p, warehouseId: $w, minimum: "2") }', ['p' => $this->productId, 'w' => $this->mx], $this->lead));
        $this->ok($this->api('mutation($i: WhWriteOffInput!) { whWriteOff(input: $i) }', ['i' => ['productId' => $this->productId, 'locationId' => $a, 'quantity' => '5', 'reason' => 'Gone', 'idempotencyKey' => 'wo-1']], $this->operator));

        $mine = $this->ok($this->api('{ whAlertInbox(unreadOnly: true) { unread items { id process read } } }', [], $this->operator))['whAlertInbox'];
        $this->assertSame([1, ['stock_out']], [$mine['unread'], array_column($mine['items'], 'process')]);
        $this->assertSame(0, $this->ok($this->api('{ whAlertInbox { unread } }', [], $this->lead))['whAlertInbox']['unread'], 'someone else\'s alerts are not mine');
        $this->assertSame(1, $this->ok($this->api('mutation { whMarkAlertsRead }', [], $this->operator))['whMarkAlertsRead']);
    }

    public function test_the_pick_list_names_the_order_the_product_and_the_shelves_to_take_it_from(): void
    {
        $a = $this->shelf('A');
        $this->receive($a, '10');
        $res = app(ReservationService::class);
        $res->reserve('order', 'order-9', [['product_id' => $this->productId, 'quantity' => 3]], 15);
        $res->consume('order', 'order-9', 'order-9', Actor::system());

        $lines = $this->ok($this->api('{ whPickLines { orderId productId sku name pending shelves { code quantity isPickable } } }', [], $this->operator))['whPickLines'];
        $this->assertSame([['orderId' => 'order-9', 'productId' => $this->productId, 'sku' => 'LIS-1', 'name' => 'Listón rojo', 'pending' => '3.000', 'shelves' => [['code' => 'A', 'quantity' => '10.000', 'isPickable' => true]]]], $lines);
        $this->ok($this->api('mutation($i: WhPickInput!) { whPick(input: $i) }', ['i' => ['orderId' => 'order-9', 'productId' => $this->productId, 'locationId' => $a, 'quantity' => '3', 'idempotencyKey' => 'pk-1']], $this->operator));
        $this->assertSame([], $this->ok($this->api('{ whPickLines { orderId } }', [], $this->operator))['whPickLines']);
    }

    public function test_integrity_and_chain_checks_belong_to_the_lead(): void
    {
        $a = $this->shelf('A');
        $this->receive($a, '5');
        $this->denied($this->api('{ whIntegrity { ok } }', [], $this->operator));
        $this->assertTrue($this->ok($this->api('{ whIntegrity { ok brokenChains } }', [], $this->lead))['whIntegrity']['ok']);
        $this->assertTrue($this->ok($this->api('query($p: ID!, $l: ID!) { whVerifyChain(productId: $p, locationId: $l) { ok brokenAt } }', ['p' => $this->productId, 'l' => $a], $this->lead))['whVerifyChain']['ok']);
        app(LocationManager::class)->ensureReception($this->mx);
    }
}
