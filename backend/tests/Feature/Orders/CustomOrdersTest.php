<?php

namespace Tests\Feature\Orders;

use Cronos\CustomOrders\Application\CustomOrderService;
use Cronos\CustomOrders\Domain\Exceptions\CustomOrderError;
use Cronos\CustomOrders\Domain\Ports\CustomOrderRepository;
use Cronos\Orders\Application\OrderPayments;
use Cronos\Orders\Domain\Events\OrderPlaced;
use Cronos\Payments\Application\OutboxRelay;
use Cronos\Payments\Domain\ValueObjects\PaymentNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

final class CustomOrdersTest extends OrdersTestCase
{
    private string $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = $this->makeUser('ana@example.com')->id;
    }

    private function service(): CustomOrderService
    {
        return app(CustomOrderService::class);
    }

    private function customRequest(): array
    {
        return $this->service()->submit($this->ana, 'ana@example.com', 'MX', 'Listón bordado para boda', 'Necesito 40 metros de listón bordado con el nombre de los novios.', ['colors' => ['marfil'], 'meters' => 40], '2027-01-15', '3000');
    }

    private function quoted(array $request): array
    {
        $q = $this->service()->createQuote($request['id'], [['description' => 'Listón bordado a medida', 'unit' => 'meter', 'quantity' => '10', 'unit_price' => '50.00']], '100.00', '2099-01-01', 'Anticipo no reembolsable', 'staff-1');

        return $this->service()->sendQuote($q['id'], 'staff-1');
    }

    public function test_a_request_is_numbered_discussed_and_internal_notes_stay_internal(): void
    {
        $r = $this->customRequest();
        $this->assertMatchesRegularExpression('/^PE-\d{6}$/', $r['number']);
        $this->assertSame('submitted', $r['status']);

        $this->service()->staffMessage($r['id'], 'staff-1', 'Cliente VIP, ofrecer descuento', true);
        $this->service()->staffMessage($r['id'], 'staff-1', '¿Qué ancho de listón prefieres?', false);
        $this->service()->customerMessage($r['id'], $this->ana, '25 mm, por favor.');

        $repo = app(CustomOrderRepository::class);
        $this->assertCount(4, $repo->messages($r['id'], true));
        $this->assertCount(3, $repo->messages($r['id'], false), 'the customer never sees internal notes');
        $this->assertSame(1, DB::table('notification_log')->where('template', 'custom_order_update')->count(), 'only the public reply is e-mailed');

        $this->expectException(CustomOrderError::class);
        $this->service()->customerMessage($r['id'], 'someone-else', 'hola');
    }

    public function test_quote_maths_follow_the_market_tax_rule_and_a_new_quote_replaces_the_open_one(): void
    {
        $r = $this->customRequest();
        $first = $this->quoted($r);
        $this->assertSame(['500.00', '68.97', '100.00', '600.00', 'sent'], [$first['subtotal'], $first['tax_total'], $first['shipping_total'], $first['grand_total'], $first['status']], 'MX prices include IVA 16 %: 500 x 16/116');
        $this->assertSame('quoted', DB::table('custom_order_requests')->value('status'));
        $this->assertSame(1, DB::table('notification_log')->where('template', 'quote_ready')->count());

        $second = $this->service()->createQuote($r['id'], [['description' => 'Listón bordado a medida', 'unit' => 'meter', 'quantity' => '10', 'unit_price' => '45.00']], '0', '2099-01-01', null, 'staff-1');
        $this->service()->sendQuote($second['id'], 'staff-1');
        $this->assertSame(['superseded', 'sent'], DB::table('custom_order_quotes')->orderBy('version')->pluck('status')->all());
        $this->assertSame(2, (int) DB::table('custom_order_quotes')->max('version'));

        foreach ([[], [['description' => 'x', 'unit' => 'meter', 'quantity' => '0', 'unit_price' => '1']]] as $bad) {
            try {
                $this->service()->createQuote($r['id'], $bad, '0', null, null, 'staff-1');
                $this->fail('invalid quote accepted');
            } catch (CustomOrderError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_accepting_creates_one_pending_order_that_is_paid_through_the_normal_payment_flow(): void
    {
        Event::fake([OrderPlaced::class]);
        $r = $this->customRequest();
        $q = $this->quoted($r);

        $order = $this->service()->accept($q['id'], $this->ana, self::ADDRESS, null);
        $again = $this->service()->accept($q['id'], $this->ana, self::ADDRESS, null);

        $this->assertSame($order['id'], $again['id'], 'a double click is one order');
        $this->assertSame(['custom', 'pending', 'unpaid', '600.00', $q['id']], [$order['order_type'], $order['status'], $order['payment_status'], $order['grand_total'], $order['source_id']]);
        $this->assertSame(['converted', $order['id']], [DB::table('custom_order_requests')->value('status'), DB::table('custom_order_requests')->value('order_id')]);
        $this->assertSame('accepted', DB::table('custom_order_quotes')->value('status'));
        $this->assertSame('Listón bordado a medida', DB::table('order_items')->value('product_name'));

        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response($this->stripeIntent(), 200)]);
        $pay = app(OrderPayments::class);
        $this->assertContains('stripe', array_column($pay->options($order), 'provider'));
        $paymentId = $pay->pay($order, 'stripe', null, null, $this->ana, null)['payment']['id'];
        $this->payments()->applyNotice($paymentId, new PaymentNotice(PaymentNotice::KIND_STATUS, $paymentId, 'pi_1', 'captured', '600.00', 'MXN', 'evt-c'), 'webhook', 'evt-c');
        app(OutboxRelay::class)->run();
        DB::table('outbox_messages')->update(['dispatched_at' => null, 'attempts' => 0]);
        app(OutboxRelay::class)->run(); // replay

        $paid = DB::table('orders')->where('id', $order['id'])->first();
        $this->assertSame(['processing', 'paid', $paymentId], [$paid->status, $paid->payment_status, $paid->payment_id]);
        Event::assertDispatchedTimes(OrderPlaced::class, 1);
        $this->assertSame(2, DB::table('order_histories')->where('order_id', $order['id'])->count());
    }

    public function test_only_the_owner_can_accept_expired_or_replaced_quotes_cannot_and_the_country_must_match(): void
    {
        $r = $this->customRequest();
        $q = $this->quoted($r);
        $eve = $this->makeUser('eve@example.com')->id;

        foreach ([
            fn () => $this->service()->accept($q['id'], $eve, self::ADDRESS, null),
            fn () => $this->service()->accept($q['id'], $this->ana, ['country_code' => 'US'] + self::ADDRESS, null),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('expected CustomOrderError');
            } catch (CustomOrderError) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, DB::table('orders')->count());

        DB::table('custom_order_quotes')->update(['valid_until' => '2000-01-01']);
        $this->assertSame(1, $this->service()->expireDue());
        $this->assertSame(['expired', 'expired'], [DB::table('custom_order_quotes')->value('status'), DB::table('custom_order_requests')->value('status')]);
        try {
            $this->service()->accept($q['id'], $this->ana, self::ADDRESS, null);
            $this->fail('an expired quote cannot be accepted');
        } catch (CustomOrderError $e) {
            $this->assertSame('QUOTE_NOT_OPEN', $e->errorCode());
        }
    }

    public function test_declining_a_quote_reopens_the_review_and_rejecting_needs_a_reason(): void
    {
        $r = $this->customRequest();
        $q = $this->quoted($r);
        $this->service()->rejectQuote($q['id'], $this->ana, 'Demasiado caro');
        $this->assertSame(['rejected', 'in_review'], [DB::table('custom_order_quotes')->value('status'), DB::table('custom_order_requests')->value('status')]);

        try {
            $this->service()->setStatus($r['id'], 'rejected', ' ', 'staff-1');
            $this->fail('reason required');
        } catch (CustomOrderError $e) {
            $this->assertSame('REASON_REQUIRED', $e->errorCode());
        }
        $this->service()->setStatus($r['id'], 'rejected', 'No trabajamos ese material', 'staff-1');
        $this->assertSame('rejected', DB::table('custom_order_requests')->value('status'));
        $this->assertSame(1, DB::table('notification_log')->where('template', 'custom_order_update')->count());
    }
}
