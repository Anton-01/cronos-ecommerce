<?php

declare(strict_types=1);

namespace Cronos\Reports\Interfaces\GraphQL;

use Cronos\Reports\Application\ReportService;
use Cronos\Reports\Domain\ValueObjects\Period;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class AdminDashboardQuery
{
    use ResolvesClientContext;

    /** Anything that needs a person is a warning; money in doubt is critical; the rest is information. */
    private const SEVERITY = [
        'checkouts_need_review' => 'critical', 'payments_with_anomaly' => 'critical', 'payments_disputed' => 'critical', 'orders_disputed' => 'critical', 'orders_cancelled_not_refunded' => 'critical',
        'webhooks_stuck' => 'warning', 'notifications_failed' => 'warning', 'outbox_stuck' => 'warning', 'orders_unshipped_3d' => 'warning', 'custom_requests_waiting' => 'warning',
        'quotes_expiring_3d' => 'info', 'low_stock_products' => 'info',
    ];

    public function __construct(private readonly ReportService $reports) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $period = Period::preset($args['period'] ?? 'LAST_7_DAYS', $this->clientContext($context)->timezone ?? 'UTC', null, $args['from'] ?? null, $args['to'] ?? null);
        $d = $this->reports->dashboard($period, $args['market'] ?? null);

        $attention = [];
        foreach ($d['attention'] as $key => $count) {
            $count > 0 && $attention[] = ['key' => $key, 'count' => $count, 'severity' => self::SEVERITY[$key] ?? 'info'];
        }
        usort($attention, fn (array $a, array $b) => [array_search($a['severity'], ['critical', 'warning', 'info']), -$a['count']] <=> [array_search($b['severity'], ['critical', 'warning', 'info']), -$b['count']]);

        return [
            'period' => $d['period'],
            'kpis' => array_map(static fn (array $k) => [
                'market' => $k['market'], 'currency' => $k['currency'], 'orders' => $k['orders'], 'revenue' => $k['revenue'], 'tax' => $k['tax'], 'shipping' => $k['shipping'], 'discounts' => $k['discounts'],
                'refunded' => $k['refunded'], 'netRevenue' => $k['net_revenue'], 'netOfTax' => $k['net_of_tax'], 'averageOrder' => $k['average_order'], 'previousOrders' => $k['previous_orders'],
                'previousNetRevenue' => $k['previous_net_revenue'], 'ordersChange' => $k['orders_change'], 'revenueChange' => $k['revenue_change'],
            ], $d['kpis']),
            'daily' => $d['daily'],
            'topProducts' => array_map(static fn (array $t) => ['market' => $t['market'], 'currency' => $t['currency'], 'productId' => $t['product_id'], 'name' => $t['name'], 'sku' => $t['sku'], 'unit' => $t['unit'],
                'quantity' => $t['quantity'], 'orders' => $t['orders'], 'netRevenue' => $t['net_revenue']], $d['top_products']),
            'funnel' => array_map(static fn (array $f) => ['market' => $f['market'], 'started' => $f['started'], 'paid' => $f['paid'], 'expired' => $f['expired'], 'cancelled' => $f['cancelled'], 'needsReview' => $f['needs_review'], 'open' => $f['open'], 'conversion' => $f['conversion']], $d['funnel']),
            'ordersByStatus' => $d['orders_by_status'],
            'payments' => array_map(static fn (array $p) => ['provider' => $p['provider'], 'mode' => $p['mode'], 'currency' => $p['currency'], 'created' => $p['created'], 'captured' => $p['captured'], 'failed' => $p['failed'],
                'cancelled' => $p['cancelled'], 'capturedAmount' => $p['captured_amount'], 'successRate' => $p['success_rate']], $d['payments']),
            'newCustomers' => $d['new_customers'], 'attention' => $attention,
        ];
    }
}
