<?php

declare(strict_types=1);

namespace Cronos\Reports\Application;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Cronos\Reports\Domain\Ports\ReportQueries;
use Cronos\Reports\Domain\ValueObjects\Period;
use Illuminate\Support\Facades\Cache;

/**
 * The numbers behind the dashboard. Everything is per market/currency (a peso and a dollar are never added), compared with the window
 * right before, and cached for a minute: the dashboard is opened by many people and refreshed often, the data barely moves in a minute.
 */
final class ReportService
{
    public const CACHE_SECONDS = 60;

    public function __construct(private readonly ReportQueries $queries) {}

    /** @return array<string, mixed> */
    public function dashboard(Period $period, ?string $market): array
    {
        return Cache::remember('reports:dashboard:'.md5($period->cacheKey().'|'.strtoupper((string) $market)), self::CACHE_SECONDS, fn () => $this->build($period, $market));
    }

    /** @return array<string, mixed> */
    private function build(Period $period, ?string $market): array
    {
        $now = $this->queries->sales($period, false, $market);
        $before = collect($this->queries->sales($period, true, $market))->keyBy('market');

        $kpis = array_map(function (array $row) use ($before): array {
            $prev = $before->get($row['market']);
            $net = BigDecimal::of($row['revenue'])->minus($row['refunded']);
            $prevNet = $prev === null ? null : BigDecimal::of($prev['revenue'])->minus($prev['refunded']);

            return $row + [
                'net_revenue' => (string) $net->toScale(2), 'net_of_tax' => (string) $net->minus($row['tax'])->toScale(2),
                'average_order' => (string) ($row['orders'] > 0 ? BigDecimal::of($row['revenue'])->dividedBy($row['orders'], 2, RoundingMode::HalfUp) : BigDecimal::zero()->toScale(2)),
                'previous_orders' => $prev['orders'] ?? 0, 'previous_net_revenue' => $prevNet === null ? '0.00' : (string) $prevNet->toScale(2),
                'orders_change' => self::change($row['orders'], $prev['orders'] ?? 0), 'revenue_change' => self::change((string) $net, $prevNet === null ? '0' : (string) $prevNet),
            ];
        }, $now);

        $funnel = array_map(static fn (array $f) => $f + ['conversion' => $f['started'] > 0 ? round($f['paid'] * 100 / $f['started'], 1) : 0.0], $this->queries->checkoutFunnel($period, $market));
        $payments = array_map(static function (array $p): array {
            $finished = $p['captured'] + $p['failed'] + $p['cancelled'];

            return $p + ['success_rate' => $finished > 0 ? round($p['captured'] * 100 / $finished, 1) : null];
        }, $this->queries->payments($period, $market));

        return [
            'period' => ['from' => $period->from, 'to' => $period->to, 'timezone' => $period->timezone, 'label' => $period->label],
            'kpis' => $kpis, 'daily' => $this->fillDays($period, $this->queries->salesByDay($period, $market)), 'top_products' => $this->queries->topProducts($period, $market, 10),
            'funnel' => $funnel, 'orders_by_status' => $this->queries->ordersByStatus($period, $market), 'payments' => $payments,
            'new_customers' => $this->queries->newCustomers($period), 'attention' => $this->queries->attention($market),
        ];
    }

    /**
     * Days with no sales still appear (as zero) so a chart's x axis is honest.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function fillDays(Period $period, array $rows): array
    {
        $byKey = [];
        $series = [];
        foreach ($rows as $r) {
            $byKey[$r['market'].'|'.$r['day']] = $r;
            $series[$r['market']] = $r['currency'];
        }
        $tz = new \DateTimeZone($period->timezone);
        $out = [];
        foreach ($series as $market => $currency) {
            for ($d = $period->from->setTimezone($tz)->setTime(0, 0); $d < $period->to; $d = $d->modify('+1 day')) {
                $day = $d->format('Y-m-d');
                $out[] = $byKey[$market.'|'.$day] ?? ['market' => $market, 'currency' => $currency, 'day' => $day, 'orders' => 0, 'revenue' => '0.00'];
            }
        }

        return $out;
    }

    /** Percentage change, one decimal; null when there is nothing to compare with. */
    public static function change(int|string $now, int|string $before): ?float
    {
        $b = BigDecimal::of($before);
        if ($b->isZero()) {
            return null;
        }

        return round((float) BigDecimal::of($now)->minus($b)->multipliedBy(100)->dividedBy($b->abs(), 4, RoundingMode::HalfUp)->toFloat(), 1);
    }
}
