<?php

namespace Tests\Feature\Orders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class OrdersApiTest extends OrdersTestCase
{
    private function order(?string $userId = null, string $qty = '2'): array
    {
        return $this->placeOrder($this->stocked($this->published($this->piece(price: '100.00')), 20), $qty, $userId);
    }

    public function test_a_guest_tracks_an_order_with_number_and_email_and_nothing_else_works(): void
    {
        $o = $this->order();
        $q = 'query($n: String!, $e: String!) { trackOrder(number: $n, email: $e) { number status paymentStatus total timeline { status } items { name quantity total } shipments { id } } }';

        $r = $this->gql($q, ['n' => $o['order_number'], 'e' => 'ANA@example.com'])->assertJsonMissingPath('errors')->json('data.trackOrder');
        $this->assertSame([$o['order_number'], 'processing', 'paid', '350.00'], [$r['number'], $r['status'], $r['paymentStatus'], $r['total']]);
        $this->assertSame([['status' => 'processing']], $r['timeline']);

        $this->gql($q, ['n' => $o['order_number'], 'e' => 'other@example.com'])->assertJsonPath('data.trackOrder', null);
        $this->gql($q, ['n' => 'CR-999999', 'e' => 'ana@example.com'])->assertJsonPath('data.trackOrder', null);
    }

    public function test_a_signed_in_shopper_sees_only_their_own_orders(): void
    {
        $ana = $this->makeUser('ana@example.com');
        $mine = $this->order($ana->id);
        $this->makeUser('eve@example.com');
        $token = $this->customerToken('eve@example.com');

        $this->gql('{ myOrders { total rows { number } } }', [], $token)->assertJsonPath('data.myOrders.total', 0);
        $this->gql('query($n: String!) { myOrder(number: $n) { number } }', ['n' => $mine['order_number']], $token)->assertJsonPath('data.myOrder', null);

        $this->gql('{ myOrders { total rows { number shippingAddress } } }', [], $this->customerToken('ana@example.com'))
            ->assertJsonPath('data.myOrders.total', 1)->assertJsonPath('data.myOrders.rows.0.number', $mine['order_number'])->assertJsonPath('data.myOrders.rows.0.shippingAddress.street', 'Calle 1');
        $this->gql('{ myOrders { total } }')->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'Unauthenticated'));
    }

    public function test_staff_manage_the_order_and_the_customer_sees_only_what_was_meant_for_them(): void
    {
        $o = $this->order();
        $admin = $this->adminToken();
        $support = $this->adminToken('help@example.com', 'support');

        $this->adminGql('{ adminOrders(search: "CR-") { total rows { number nextStatuses } } }', [], $support)->assertJsonPath('data.adminOrders.total', 1);
        $this->adminGql('mutation($id: ID!) { transitionOrder(id: $id, to: "shipped") { status } }', ['id' => $o['id']], $support)
            ->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'unauthorized'));

        $this->adminGql('mutation($id: ID!) { transitionOrder(id: $id, to: "delivered") { status } }', ['id' => $o['id']], $admin)->assertJsonPath('errors.0.extensions.code', 'INVALID_TRANSITION');
        $this->adminGql('mutation($id: ID!) { transitionOrder(id: $id, to: "shipped", note: "Interno: caja dañada", notifyCustomer: false) { status nextStatuses } }', ['id' => $o['id']], $admin)
            ->assertJsonPath('data.transitionOrder.nextStatuses', ['delivered']);
        $this->adminGql('mutation($id: ID!) { transitionOrder(id: $id, to: "delivered", note: "Entregado en recepción", notifyCustomer: true) { status } }', ['id' => $o['id']], $admin);

        $timeline = $this->gql('query($n: String!) { trackOrder(number: $n, email: "ana@example.com") { timeline { status note } } }', ['n' => $o['order_number']])->json('data.trackOrder.timeline');
        $this->assertSame(['processing', 'delivered'], array_column($timeline, 'status'), 'the internal move to shipped is not shown');
        $this->assertStringNotContainsString('caja', json_encode($timeline));

        $h = $this->adminGql('query($id: ID!) { adminOrder(id: $id) { history { status note notifyCustomer } fraudDecision } }', ['id' => $o['id']], $admin)->json('data.adminOrder');
        $this->assertCount(3, $h['history'], 'staff see everything, including the internal note');
    }

    public function test_staff_ship_and_cancel_through_the_api(): void
    {
        $o = $this->order();
        $admin = $this->adminToken();
        $item = DB::table('order_items')->value('id');

        $s = $this->adminGql('mutation($i: CreateShipmentInput!) { createShipment(input: $i) { id carrier status trackingNumber items { name quantity } } }',
            ['i' => ['orderId' => $o['id'], 'carrier' => 'dhl', 'trackingNumber' => 'D777', 'items' => [['orderItemId' => (string) $item, 'quantity' => '1']]]], $admin)
            ->assertJsonMissingPath('errors')->json('data.createShipment');
        $this->assertSame(['dhl', 'in_transit', 'D777'], [$s['carrier'], $s['status'], $s['trackingNumber']]);
        $this->assertSame('1', $s['items'][0]['quantity']);

        $this->gql('query($n: String!) { trackOrder(number: $n, email: "ana@example.com") { fulfillmentStatus shipments { carrier events { status } } } }', ['n' => $o['order_number']])
            ->assertJsonPath('data.trackOrder.fulfillmentStatus', 'partial')->assertJsonPath('data.trackOrder.shipments.0.events.0.status', 'in_transit');

        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded'], 200)]);
        $this->adminGql('mutation($id: ID!) { cancelOrder(id: $id, reason: " ") { order { status } } }', ['id' => $o['id']], $admin)->assertJsonPath('errors.0.extensions.code', 'REASON_REQUIRED');
        $this->adminGql('mutation($id: ID!) { cancelOrder(id: $id, reason: "Pidió cancelar") { order { status paymentStatus refunded } refundError } }', ['id' => $o['id']], $admin)
            ->assertJsonPath('data.cancelOrder.order.status', 'cancelled')->assertJsonPath('data.cancelOrder.refundError', null);
    }
}
