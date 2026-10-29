<?php

namespace Tests\Feature\Orders;

use Cronos\Orders\Application\OrderService;
use Cronos\Shipping\Application\ShipmentService;
use Cronos\Shipping\Domain\Exceptions\ShippingError;
use Illuminate\Support\Facades\DB;

final class ShippingTest extends OrdersTestCase
{
    private function shipments(): ShipmentService
    {
        return app(ShipmentService::class);
    }

    private function orderOf(string $qty = '4'): array
    {
        return $this->placeOrder($this->stocked($this->published($this->piece(price: '100.00')), 20), $qty);
    }

    private function sign(string $body, string $secret): array
    {
        return ['HTTP_X_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $secret), 'CONTENT_TYPE' => 'application/json'];
    }

    public function test_shipping_everything_moves_the_order_to_shipped_and_a_second_attempt_has_nothing_to_ship(): void
    {
        $order = $this->orderOf();
        $s = $this->shipments()->create($order['id'], 'Estafeta', 'Terrestre', 'EST123', 'https://track.test/EST123', [], [['weight_kg' => '1.2']], '150.00', null, null);

        $this->assertSame(['estafeta', 'in_transit'], [$s['carrier'], $s['status']]);
        $this->assertSame([['order_item_id' => DB::table('order_items')->value('id'), 'quantity' => '4']], $s['items']);
        $o = DB::table('orders')->where('id', $order['id'])->first();
        $this->assertSame(['shipped', 'shipped'], [$o->status, $o->fulfillment_status]);

        $this->expectException(ShippingError::class);
        $this->shipments()->create($order['id'], 'dhl', null, null, null, [], [], null, null, null);
    }

    public function test_partial_shipments_keep_the_order_processing_until_the_last_piece_leaves(): void
    {
        $order = $this->orderOf('4');
        $item = (int) DB::table('order_items')->value('id');

        $this->shipments()->create($order['id'], 'dhl', null, 'D1', null, [['order_item_id' => $item, 'quantity' => '1.5']], [], null, null, null);
        $o = DB::table('orders')->where('id', $order['id'])->first();
        $this->assertSame(['processing', 'partial'], [$o->status, $o->fulfillment_status]);

        foreach (['5', '0'] as $bad) {
            try {
                $this->shipments()->create($order['id'], 'dhl', null, null, null, [['order_item_id' => $item, 'quantity' => $bad]], [], null, null, null);
                $this->fail("quantity {$bad} must be refused");
            } catch (ShippingError $e) {
                $this->assertSame('INVALID_QUANTITY', $e->errorCode());
            }
        }

        $this->shipments()->create($order['id'], 'dhl', null, 'D2', null, [], [], null, null, null);
        $this->assertSame('shipped', DB::table('orders')->where('id', $order['id'])->value('status'));
    }

    public function test_carrier_events_are_idempotent_forward_only_and_deliver_the_order(): void
    {
        $order = $this->orderOf();
        $this->shipments()->create($order['id'], 'estafeta', null, 'EST9', null, [], [], null, null, null);
        $at = new \DateTimeImmutable('2026-10-01 10:00:00');

        $this->assertTrue($this->shipments()->ingest('estafeta', 'EST9', 'e1', 'out_for_delivery', 'En reparto', 'CDMX', $at, null));
        $this->assertFalse($this->shipments()->ingest('estafeta', 'EST9', 'e1', 'out_for_delivery', 'En reparto', 'CDMX', $at, null), 'a replay is recorded once');
        $this->assertTrue($this->shipments()->ingest('estafeta', 'EST9', 'e0', 'in_transit', 'Salió de bodega', null, $at->modify('-1 day'), null), 'a late event is kept…');
        $this->assertSame('out_for_delivery', DB::table('shipments')->value('status'), '…but never moves the shipment backwards');
        $this->assertFalse($this->shipments()->ingest('estafeta', 'NOPE', 'x', 'delivered', null, null, $at, null), 'unknown tracking numbers are ignored');

        $this->shipments()->ingest('estafeta', 'EST9', 'e2', 'delivered', 'Entregado', 'CDMX', $at->modify('+3 hours'), null);
        $o = DB::table('orders')->where('id', $order['id'])->first();
        $this->assertSame(['delivered', 'delivered'], [$o->status, $o->fulfillment_status]);
        $this->assertNotNull(DB::table('shipments')->value('delivered_at'));
        $this->assertSame(4, DB::table('shipment_tracking_events')->count(), 'created + 3 carrier events');
    }

    public function test_the_webhook_demands_a_valid_signature_and_applies_signed_events(): void
    {
        config(['shipping.carriers.estafeta.webhook_secret' => 'ship-secret']);
        $order = $this->orderOf();
        $this->shipments()->create($order['id'], 'estafeta', null, 'EST7', null, [], [], null, null, null);
        $body = json_encode(['events' => [['event_id' => 'w1', 'tracking_number' => 'EST7', 'status' => 'delivered', 'description' => 'Entregado', 'occurred_at' => '2026-10-02T12:00:00Z']]]);

        $this->call('POST', '/webhooks/shipping/estafeta', [], [], [], $this->sign($body, 'wrong'), $body)->assertStatus(401);
        $this->call('POST', '/webhooks/shipping/unknown', [], [], [], $this->sign($body, ''), $body)->assertStatus(401);
        $this->assertSame('in_transit', DB::table('shipments')->value('status'));

        $this->call('POST', '/webhooks/shipping/estafeta', [], [], [], $this->sign($body, 'ship-secret'), $body)->assertOk()->assertJson(['accepted' => 1]);
        $this->call('POST', '/webhooks/shipping/estafeta', [], [], [], $this->sign($body, 'ship-secret'), $body)->assertOk()->assertJson(['accepted' => 0]);
        $this->assertSame('delivered', DB::table('orders')->where('id', $order['id'])->value('status'));
    }

    public function test_unpaid_or_cancelled_orders_cannot_be_shipped(): void
    {
        $order = $this->orderOf();
        app(OrderService::class)->cancel($order['id'], 'x', false, null);

        $this->expectException(ShippingError::class);
        $this->shipments()->create($order['id'], 'dhl', null, null, null, [], [], null, null, null);
    }
}
