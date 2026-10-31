<?php

declare(strict_types=1);

namespace Cronos\Reports\Domain\Ports;

use Cronos\Reports\Domain\ValueObjects\Period;

/** Read-only questions about the business. Money is never added across currencies: every figure carries its market and currency. */
interface ReportQueries
{
    /** @return list<array{market: string, currency: string, orders: int, revenue: string, tax: string, shipping: string, discounts: string, refunded: string}> one row per market with sales */
    public function sales(Period $period, bool $previous, ?string $market): array;

    /** @return list<array{market: string, currency: string, day: string, orders: int, revenue: string}> */
    public function salesByDay(Period $period, ?string $market): array;

    /** @return list<array{market: string, currency: string, product_id: ?string, name: string, sku: ?string, unit: string, quantity: string, orders: int, net_revenue: string}> best first */
    public function topProducts(Period $period, ?string $market, int $limit): array;

    /** @return list<array{market: string, started: int, paid: int, expired: int, cancelled: int, needs_review: int, open: int}> */
    public function checkoutFunnel(Period $period, ?string $market): array;

    /** @return list<array{status: string, orders: int}> */
    public function ordersByStatus(Period $period, ?string $market): array;

    /** @return list<array{provider: string, mode: string, created: int, captured: int, failed: int, cancelled: int, captured_amount: string, currency: string}> */
    public function payments(Period $period, ?string $market): array;

    public function newCustomers(Period $period): int;

    /** @return array<string, int> things that need a person, by key */
    public function attention(?string $market): array;
}
