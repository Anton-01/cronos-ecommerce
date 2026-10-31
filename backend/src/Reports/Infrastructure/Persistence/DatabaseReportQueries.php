<?php

declare(strict_types=1);

namespace Cronos\Reports\Infrastructure\Persistence;

use Cronos\Reports\Domain\Ports\ReportQueries;
use Cronos\Reports\Domain\ValueObjects\Period;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DatabaseReportQueries implements ReportQueries
{
    /** An order counts as a sale once money arrived for it (even if it was later cancelled or refunded: refunds are reported next to it). */
    private const SOLD = ['paid', 'partially_refunded', 'refunded', 'disputed'];

    public function sales(Period $period, bool $previous, ?string $market): array
    {
        [$from, $to] = $previous ? [$period->previousFrom, $period->previousTo] : [$period->from, $period->to];

        return $this->orders($from, $to, $market)->groupBy('o.market_code', 'o.currency')->orderBy('o.market_code')
            ->selectRaw('o.market_code AS market, o.currency, COUNT(*) AS orders, SUM(o.grand_total) AS revenue, SUM(o.tax_total) AS tax, SUM(o.shipping_total) AS shipping, SUM(o.discount_total) AS discounts, SUM(o.refunded_total) AS refunded')
            ->get()->map(static fn ($r) => ['market' => $r->market, 'currency' => $r->currency, 'orders' => (int) $r->orders, 'revenue' => (string) $r->revenue, 'tax' => (string) $r->tax,
                'shipping' => (string) $r->shipping, 'discounts' => (string) $r->discounts, 'refunded' => (string) $r->refunded])->all();
    }

    public function salesByDay(Period $period, ?string $market): array
    {
        return $this->orders($period->from, $period->to, $market)
            ->selectRaw('o.market_code AS market, o.currency, (o.placed_at AT TIME ZONE ?)::date AS day, COUNT(*) AS orders, SUM(o.grand_total) AS revenue', [$period->timezone])
            ->groupByRaw('o.market_code, o.currency, day')->orderBy('day')->orderBy('market')
            ->get()->map(static fn ($r) => ['market' => $r->market, 'currency' => $r->currency, 'day' => (string) $r->day, 'orders' => (int) $r->orders, 'revenue' => (string) $r->revenue])->all();
    }

    public function topProducts(Period $period, ?string $market, int $limit): array
    {
        return $this->orders($period->from, $period->to, $market)
            ->join('order_items as i', 'i.order_id', '=', 'o.id')
            ->selectRaw('o.market_code AS market, o.currency, i.product_id, MAX(i.product_name) AS name, MAX(i.sku) AS sku, i.unit, SUM(i.quantity) AS quantity, COUNT(DISTINCT o.id) AS orders, SUM(CASE WHEN o.tax_included THEN i.total - i.tax_amount ELSE i.total END) AS net_revenue')
            ->groupByRaw('o.market_code, o.currency, i.product_id, i.unit, COALESCE(i.product_id, i.product_name)')->orderByDesc('net_revenue')->limit($limit)
            ->get()->map(static fn ($r) => ['market' => $r->market, 'currency' => $r->currency, 'product_id' => $r->product_id, 'name' => $r->name, 'sku' => $r->sku, 'unit' => $r->unit,
                'quantity' => rtrim(rtrim((string) $r->quantity, '0'), '.') ?: '0', 'orders' => (int) $r->orders, 'net_revenue' => (string) $r->net_revenue])->all();
    }

    public function checkoutFunnel(Period $period, ?string $market): array
    {
        return DB::table('checkouts')->where('created_at', '>=', $period->from)->where('created_at', '<', $period->to)
            ->when($market, fn ($q) => $q->where('market_code', strtoupper($market)))->groupBy('market_code')->orderBy('market_code')
            ->selectRaw("market_code AS market, COUNT(*) AS started, COUNT(*) FILTER (WHERE status = 'paid') AS paid, COUNT(*) FILTER (WHERE status = 'expired') AS expired,
                COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled, COUNT(*) FILTER (WHERE status = 'needs_review') AS needs_review, COUNT(*) FILTER (WHERE status = 'open') AS open")
            ->get()->map(static fn ($r) => ['market' => $r->market, 'started' => (int) $r->started, 'paid' => (int) $r->paid, 'expired' => (int) $r->expired, 'cancelled' => (int) $r->cancelled,
                'needs_review' => (int) $r->needs_review, 'open' => (int) $r->open])->all();
    }

    public function ordersByStatus(Period $period, ?string $market): array
    {
        return DB::table('orders')->whereNull('deleted_at')->where('created_at', '>=', $period->from)->where('created_at', '<', $period->to)
            ->when($market, fn ($q) => $q->where('market_code', strtoupper($market)))->groupBy('status')->orderBy('status')
            ->selectRaw('status, COUNT(*) AS orders')->get()->map(static fn ($r) => ['status' => $r->status, 'orders' => (int) $r->orders])->all();
    }

    public function payments(Period $period, ?string $market): array
    {
        $q = DB::table('payments as p')->where('p.created_at', '>=', $period->from)->where('p.created_at', '<', $period->to);
        // A payment has no market of its own; its currency is the market's (one currency per market).
        $market === null || $q->whereRaw('p.currency = (SELECT c.code FROM markets m JOIN currencies c ON c.id = m.currency_id WHERE m.code = ?)', [strtoupper($market)]);

        return $q->groupBy('p.provider', 'p.mode', 'p.currency')->orderBy('p.provider')->orderBy('p.mode')
            ->selectRaw("p.provider, p.mode, p.currency, COUNT(*) AS created, COUNT(*) FILTER (WHERE p.status IN ('captured','partially_refunded','refunded','disputed')) AS captured,
                COUNT(*) FILTER (WHERE p.status = 'failed') AS failed, COUNT(*) FILTER (WHERE p.status = 'cancelled') AS cancelled,
                COALESCE(SUM(p.captured_amount), 0) AS captured_amount")
            ->get()->map(static fn ($r) => ['provider' => $r->provider, 'mode' => $r->mode, 'currency' => $r->currency, 'created' => (int) $r->created, 'captured' => (int) $r->captured,
                'failed' => (int) $r->failed, 'cancelled' => (int) $r->cancelled, 'captured_amount' => (string) $r->captured_amount])->all();
    }

    public function newCustomers(Period $period): int
    {
        return DB::table('users as u')->join('model_has_roles as mr', fn ($j) => $j->on('mr.model_id', '=', 'u.id')->where('mr.model_type', 'like', '%UserModel'))
            ->join('roles as r', 'r.id', '=', 'mr.role_id')->where('r.name', 'customer')->whereNull('u.deleted_at')
            ->where('u.created_at', '>=', $period->from)->where('u.created_at', '<', $period->to)->count();
    }

    public function attention(?string $market): array
    {
        $m = $market === null ? null : strtoupper($market);
        $orders = static fn (): Builder => DB::table('orders')->whereNull('deleted_at')->when($m, fn ($q) => $q->where('market_code', $m));

        return [
            'checkouts_need_review' => DB::table('checkouts')->where('status', 'needs_review')->when($m, fn ($q) => $q->where('market_code', $m))->count(),
            'payments_with_anomaly' => DB::table('payments')->whereNotNull('anomaly')->whereNotIn('status', ['refunded'])->count(),
            'payments_disputed' => DB::table('payments')->where('status', 'disputed')->count(),
            'webhooks_stuck' => DB::table('webhook_events')->whereIn('status', ['failed', 'dead'])->count(),
            'notifications_failed' => DB::table('notification_log')->where('status', 'failed')->count(),
            'orders_unshipped_3d' => $orders()->where('status', 'processing')->whereIn('payment_status', ['paid', 'partially_refunded'])->where('fulfillment_status', 'unfulfilled')->where('placed_at', '<', now()->subDays(3))->count(),
            'orders_disputed' => $orders()->where('payment_status', 'disputed')->count(),
            'orders_cancelled_not_refunded' => $orders()->where('status', 'cancelled')->where('payment_status', 'paid')->count(),
            'custom_requests_waiting' => DB::table('custom_order_requests')->whereNull('deleted_at')->when($m, fn ($q) => $q->where('market_code', $m))->where('status', 'submitted')->where('created_at', '<', now()->subDay())->count(),
            'quotes_expiring_3d' => DB::table('custom_order_quotes as q')->join('custom_order_requests as r', 'r.id', '=', 'q.request_id')->where('q.status', 'sent')->whereBetween('q.valid_until', [now()->toDateString(), now()->addDays(3)->toDateString()])->when($m, fn ($q) => $q->where('r.market_code', $m))->count(),
            'low_stock_products' => $this->lowStock($m),
            'outbox_stuck' => DB::table('outbox_messages')->whereNull('dispatched_at')->where('attempts', '>=', 5)->count(),
        ];
    }

    private function lowStock(?string $market): int
    {
        $levels = DB::table('stock_levels as s')->join('products as p', 'p.id', '=', 's.product_id')->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->whereNull('p.deleted_at')->where('p.status', 'published')->where('w.is_active', true)->whereNotNull('p.low_stock_threshold')
            ->when($market !== null && config('inventory.market_isolation', true), fn ($q) => $q->where(fn ($w) => $w->whereNull('w.market_id')->orWhereRaw('w.market_id = (SELECT id FROM markets WHERE code = ?)', [$market])))
            ->groupBy('p.id', 'p.low_stock_threshold')->havingRaw('SUM(s.available) <= p.low_stock_threshold')->select('p.id');

        return DB::table(DB::raw("({$levels->toSql()}) AS low"))->mergeBindings($levels)->count();
    }

    private function orders(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $market): Builder
    {
        return DB::table('orders as o')->whereNull('o.deleted_at')->whereIn('o.payment_status', self::SOLD)->where('o.placed_at', '>=', $from)->where('o.placed_at', '<', $to)
            ->when($market, fn ($q) => $q->where('o.market_code', strtoupper($market)));
    }
}
