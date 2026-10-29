<?php

namespace Tests\Feature\Orders;

use Cronos\Orders\Application\OrderPlacer;
use Cronos\Orders\Application\OrderService;
use Cronos\Orders\Domain\Events\OrderPlaced;
use Cronos\Orders\Domain\Exceptions\OrderError;
use Cronos\Orders\Domain\Ports\OrderRepository;
use Cronos\Payments\Application\OutboxRelay;
use Cronos\Payments\Application\PaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

final class OrdersTest extends OrdersTestCase
{
    private function service(): OrderService
    {
        return app(OrderService::class);
    }

    public function test_a_paid_checkout_becomes_one_order_with_everything_the_shopper_saw(): void
    {
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 10);
        $order = $this->placeOrder($bow);

        $this->assertMatchesRegularExpression('/^CR-\d{6}$/', $order['order_number']);
        $this->assertSame(['standard', 'processing', 'paid', 'unfulfilled', '200.00', '150.00', '27.59', '350.00', 'MXN'],
            [$order['order_type'], $order['status'], $order['payment_status'], $order['fulfillment_status'], $order['subtotal'], $order['shipping_total'], $order['tax_total'], $order['grand_total'], $order['currency']]);

        $item = DB::table('order_items')->where('order_id', $order['id'])->first();
        $this->assertSame(['MONO-1', 'piece', '2.000', '100.00', '200.00'], [$item->sku, $item->unit, (string) $item->quantity, (string) $item->unit_price, (string) $item->total]);
        $this->assertEqualsWithDelta(16.0, (float) $item->tax_rate, 0.0001);

        $this->assertNotSame('Calle 1', DB::table('order_addresses')->where(['order_id' => $order['id'], 'type' => 'shipping'])->value('data'), 'encrypted at rest');
        $this->assertSame('Calle 1', app(OrderRepository::class)->addresses($order['id'])['shipping']['street']);
        $this->assertSame(['processing', 'paid', 1], [DB::table('order_histories')->value('status'), DB::table('order_histories')->value('payment_status'), DB::table('order_histories')->count()]);
    }

    public function test_replaying_the_announcement_never_creates_a_second_order(): void
    {
        Event::fake([OrderPlaced::class]);
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 10);
        $order = $this->placeOrder($bow);

        DB::table('outbox_messages')->update(['dispatched_at' => null, 'attempts' => 0]);
        app(OutboxRelay::class)->run();
        $again = app(OrderPlacer::class)->fromCheckout($order['checkout_id']);

        $this->assertSame($order['id'], $again['id']);
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(1, DB::table('order_histories')->count());
        Event::assertDispatchedTimes(OrderPlaced::class, 1);
    }

    public function test_the_order_follows_the_logistic_state_machine_and_every_move_is_written_down(): void
    {
        $order = $this->placeOrder($this->stocked($this->published($this->piece()), 10), '1');

        $this->service()->transition($order['id'], 'shipped', 'Paquetería Estafeta', true, null);
        $this->service()->transition($order['id'], 'delivered', null, true, null);
        $this->service()->transition($order['id'], 'completed', null, false, null);
        $this->assertSame(['processing', 'shipped', 'delivered', 'completed'], DB::table('order_histories')->orderBy('id')->pluck('status')->all());

        foreach ([['completed', 'shipped'], ['completed', 'processing']] as [, $to]) {
            try {
                $this->service()->transition($order['id'], $to, null, false, null);
                $this->fail('expected INVALID_TRANSITION');
            } catch (OrderError $e) {
                $this->assertSame('INVALID_TRANSITION', $e->errorCode());
            }
        }
        $this->expectException(QueryException::class);
        DB::table('order_histories')->update(['note' => 'tampered']);
    }

    public function test_cancelling_puts_the_stock_back_and_refunds_the_money_once(): void
    {
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 10);
        $order = $this->placeOrder($bow);
        $this->assertSame('8.000', (string) DB::table('stock_levels')->where(['product_id' => $bow, 'warehouse_id' => $this->mx])->value('on_hand'));

        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded'], 200)]);
        $r = $this->service()->cancel($order['id'], 'Cambio de opinión', true, null);
        app(OutboxRelay::class)->run();

        $this->assertNull($r['refund_error']);
        $fresh = (array) DB::table('orders')->where('id', $order['id'])->first();
        $this->assertSame(['cancelled', 'refunded', '350.00'], [$fresh['status'], $fresh['payment_status'], (string) $fresh['refunded_total']]);
        $this->assertNotNull($fresh['cancelled_at']);
        $this->assertSame('10.000', (string) DB::table('stock_levels')->where(['product_id' => $bow, 'warehouse_id' => $this->mx])->value('on_hand'), 'the two pieces are back on the shelf');
        $this->assertSame(1, DB::table('inventory_movements')->where('reference_type', 'order_return')->count());

        try {
            $this->service()->cancel($order['id'], 'otra vez', true, null);
            $this->fail('a cancelled order cannot be cancelled again');
        } catch (OrderError $e) {
            $this->assertSame('INVALID_TRANSITION', $e->errorCode());
        }
        $this->assertSame('10.000', (string) DB::table('stock_levels')->where(['product_id' => $bow, 'warehouse_id' => $this->mx])->value('on_hand'));
    }

    public function test_a_provider_outage_still_cancels_the_order_and_says_the_refund_is_pending(): void
    {
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 10);
        $order = $this->placeOrder($bow);

        Http::fake(['api.stripe.com/v1/refunds' => Http::response([], 503)]);
        $r = $this->service()->cancel($order['id'], 'Sin stock real', true, null);

        $this->assertNotNull($r['refund_error']);
        $this->assertSame('cancelled', DB::table('orders')->where('id', $order['id'])->value('status'));
        $this->assertSame('10.000', (string) DB::table('stock_levels')->where(['product_id' => $bow, 'warehouse_id' => $this->mx])->value('on_hand'));
        $this->assertStringContainsString('Refund pending', (string) DB::table('order_histories')->orderByDesc('id')->value('note'));
    }

    public function test_a_partial_refund_made_in_payments_shows_on_the_order(): void
    {
        $order = $this->placeOrder($this->stocked($this->published($this->piece(price: '100.00')), 10));
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded'], 200)]);

        app(PaymentService::class)->refund($order['payment_id'], '50.00', 'Descuento por demora', 'partial-1', null);
        app(OutboxRelay::class)->run();

        $fresh = (array) DB::table('orders')->where('id', $order['id'])->first();
        $this->assertSame(['processing', 'partially_refunded', '50.00'], [$fresh['status'], $fresh['payment_status'], (string) $fresh['refunded_total']]);
    }
}
