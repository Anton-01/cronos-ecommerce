<?php

namespace Tests\Feature\Reports;

use Cronos\Payments\Application\OutboxRelay;
use Cronos\Payments\Application\PaymentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Orders\OrdersTestCase;

final class DashboardTest extends OrdersTestCase
{
    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->adminToken();
    }

    private const Q = 'query($p: PeriodPreset, $m: String, $f: String, $t: String) { adminDashboard(period: $p, market: $m, from: $f, to: $t) {
        period { timezone label } newCustomers
        kpis { market currency orders revenue tax shipping refunded netRevenue netOfTax averageOrder previousOrders ordersChange revenueChange }
        daily { market day orders revenue } topProducts { market name quantity unit orders netRevenue } funnel { market started paid conversion }
        payments { provider mode captured successRate } ordersByStatus { status orders } attention { key count severity } } }';

    private function dashboard(array $vars = []): array
    {
        Cache::flush();

        return $this->adminGql(self::Q, $vars + ['p' => 'LAST_7_DAYS'], $this->admin)->assertJsonMissingPath('errors')->json('data.adminDashboard');
    }

    private function usOrder(string $total = '50.00', ?string $placedAt = null): void
    {
        DB::table('orders')->insert([
            'id' => (string) Str::ulid(), 'order_number' => 'CR-9'.random_int(10000, 99999), 'order_type' => 'standard', 'email' => 'bob@example.com', 'market_code' => 'US', 'currency' => 'USD',
            'tax_included' => false, 'status' => 'processing', 'payment_status' => 'paid', 'fulfillment_status' => 'unfulfilled', 'subtotal' => $total, 'discount_total' => 0, 'shipping_total' => 0, 'tax_total' => 0,
            'grand_total' => $total, 'placed_at' => $placedAt ?? now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_sales_are_counted_per_store_and_never_added_across_currencies(): void
    {
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 50);
        $this->placeOrder($bow, '2', null, 'a');
        $this->placeOrder($bow, '2', null, 'b');
        $this->usOrder('50.00');

        $d = $this->dashboard();
        $kpis = collect($d['kpis'])->keyBy('market');
        $this->assertSame(['MXN', 2, '700.00', '55.18', '300.00', '700.00', '644.82', '350.00'], [$kpis['MX']['currency'], $kpis['MX']['orders'], $kpis['MX']['revenue'], $kpis['MX']['tax'], $kpis['MX']['shipping'], $kpis['MX']['netRevenue'], $kpis['MX']['netOfTax'], $kpis['MX']['averageOrder']]);
        $this->assertSame(['USD', 1, '50.00'], [$kpis['US']['currency'], $kpis['US']['orders'], $kpis['US']['revenue']]);

        $us = $this->dashboard(['m' => 'US']);
        $this->assertSame(['US'], array_column($us['kpis'], 'market'));
        $this->assertSame(['US'], array_values(array_unique(array_column($us['daily'], 'market'))));
    }

    public function test_it_compares_with_the_window_before_and_refunds_reduce_net_revenue(): void
    {
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 50);
        $old = $this->placeOrder($bow, '2', null, 'old');
        DB::table('orders')->where('id', $old['id'])->update(['placed_at' => now()->subDays(10)]); // inside the previous 7-day window
        $this->placeOrder($bow, '2', null, 'new-1');
        $this->placeOrder($bow, '2', null, 'new-2');

        $mx = collect($this->dashboard()['kpis'])->firstWhere('market', 'MX');
        $this->assertEquals([2, 1, 100, 100], [$mx['orders'], $mx['previousOrders'], $mx['ordersChange'], $mx['revenueChange']]);

        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded'], 200)]);
        $latest = DB::table('orders')->orderByDesc('placed_at')->first();
        app(PaymentService::class)->refund($latest->payment_id, '100.00', 'Cortesía', 'r-1', null);
        app(OutboxRelay::class)->run();

        $mx = collect($this->dashboard()['kpis'])->firstWhere('market', 'MX');
        $this->assertSame(['700.00', '100.00', '600.00'], [$mx['revenue'], $mx['refunded'], $mx['netRevenue']]);
    }

    public function test_every_day_of_the_window_is_present_even_without_sales(): void
    {
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 50);
        $this->placeOrder($bow, '1');

        $days = collect($this->dashboard()['daily'])->where('market', 'MX');
        $this->assertCount(7, $days);
        $this->assertSame(1, $days->sum('orders'));
        $this->assertSame(6, $days->where('orders', 0)->count());
    }

    public function test_best_sellers_funnel_payments_and_statuses(): void
    {
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 50);
        $ribbon = $this->stocked($this->published($this->piece('Listón', 'LIST-1', '40.00')), 50);
        $this->placeOrder($bow, '3', null, 'a');
        $this->placeOrder($ribbon, '1', null, 'b');

        $d = $this->dashboard();
        $this->assertSame(['Moño decorativo', '3', 'piece'], [$d['topProducts'][0]['name'], $d['topProducts'][0]['quantity'], $d['topProducts'][0]['unit']]);
        $this->assertSame('258.62', $d['topProducts'][0]['netRevenue'], '300 with IVA inside → 258.62 without tax');
        $this->assertEquals(['MX', 2, 2, 100], [$d['funnel'][0]['market'], $d['funnel'][0]['started'], $d['funnel'][0]['paid'], $d['funnel'][0]['conversion']]);
        $this->assertEquals(['stripe', 'sandbox', 2, 100], [$d['payments'][0]['provider'], strtolower($d['payments'][0]['mode']), $d['payments'][0]['captured'], $d['payments'][0]['successRate']]);
        $this->assertSame([['status' => 'processing', 'orders' => 2]], $d['ordersByStatus']);
    }

    public function test_what_needs_a_person_is_listed_worst_first_and_quiet_items_stay_out(): void
    {
        $this->assertSame([], $this->dashboard()['attention'], 'a calm shop has nothing to show');

        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 50);
        $order = $this->placeOrder($bow, '1');
        DB::table('checkouts')->where('id', $order['checkout_id'])->update(['status' => 'needs_review']);
        DB::table('webhook_events')->insert(['id' => (string) Str::ulid(), 'provider' => 'stripe', 'mode' => 'sandbox', 'event_id' => 'e1', 'event_type' => 'x', 'signature_valid' => true, 'raw_body' => '{}', 'headers' => '{}', 'status' => 'dead', 'attempts' => 7, 'received_at' => now()]);
        DB::table('orders')->where('id', $order['id'])->update(['placed_at' => now()->subDays(4)]);
        DB::table('products')->where('id', $bow)->update(['low_stock_threshold' => 100]);

        $items = collect($this->dashboard()['attention'])->keyBy('key');
        $this->assertSame('critical', $items['checkouts_need_review']['severity']);
        $this->assertSame(['warning', 'warning', 'info'], [$items['webhooks_stuck']['severity'], $items['orders_unshipped_3d']['severity'], $items['low_stock_products']['severity']]);
        $this->assertSame('critical', $this->dashboard()['attention'][0]['severity'], 'critical first');
        $this->assertFalse($items->has('notifications_failed'));
    }

    public function test_custom_periods_are_validated_and_only_staff_with_reports_view_can_read(): void
    {
        $this->adminGql(self::Q, ['p' => 'CUSTOM', 'f' => '2026-02-01', 't' => '2026-01-01'], $this->admin)->assertJsonPath('errors.0.extensions.code', 'INVALID_PERIOD');
        $this->adminGql(self::Q, ['p' => 'CUSTOM', 'f' => '2020-01-01', 't' => '2026-01-01'], $this->admin)->assertJsonPath('errors.0.extensions.code', 'PERIOD_TOO_LONG');
        $this->assertSame('CUSTOM', $this->dashboard(['p' => 'CUSTOM', 'f' => '2026-01-01', 't' => '2026-01-31'])['period']['label']);

        $support = $this->adminToken('help@example.com', 'support');
        $this->adminGql(self::Q, ['p' => 'TODAY'], $support)->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'unauthorized'));
    }
}
