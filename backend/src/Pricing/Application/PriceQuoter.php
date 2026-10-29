<?php

declare(strict_types=1);

namespace Cronos\Pricing\Application;

use Cronos\Pricing\Domain\Exceptions\NotFound;
use Cronos\Pricing\Domain\Ports\PricingCatalog;
use Cronos\Pricing\Domain\Ports\ProductPriceStore;
use Cronos\Pricing\Domain\Services\PriceResolver;
use Cronos\Pricing\Domain\Services\TaxCalculator;
use Cronos\Pricing\Domain\ValueObjects\Money;
use Cronos\Pricing\Domain\ValueObjects\PriceRow;
use Cronos\Pricing\Domain\ValueObjects\TaxBreakdown;
use Cronos\Pricing\Domain\ValueObjects\TaxRate;
use DateTimeImmutable;

/**
 * What a shopper in a market sees for a set of products: price (sale/compare-at aware), currency and tax.
 * Two queries for any number of products; markets, currencies and tax rules come from the cached snapshot.
 */
final class PriceQuoter
{
    public function __construct(
        private readonly PricingCatalog $catalog,
        private readonly ProductPriceStore $prices,
        private readonly PriceResolver $resolver,
        private readonly TaxCalculator $tax,
    ) {}

    /**
     * @param  list<string>  $productIds
     * @return array<string, array<string, mixed>|null> product id => quote, null when the product has no price in that market
     */
    public function quote(array $productIds, string $marketCode, ?string $region = null, ?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable;
        $snapshot = $this->catalog->snapshot();
        $market = collect($snapshot['markets'])->firstWhere('code', strtoupper($marketCode));
        ($market !== null && $market['is_active']) || throw new NotFound("The market {$marketCode}");
        $currencies = collect($snapshot['currencies']);
        $currency = $currencies->firstWhere('id', $market['currency_id']);
        $default = $currencies->firstWhere('is_default', true);
        $defaultClass = collect($snapshot['tax_classes'])->firstWhere('is_default', true)['id'] ?? null;

        $base = $this->prices->basePrices($productIds);
        $rows = $this->prices->rowsFor($productIds, (int) $currency['id']);
        $region = $region === null || trim($region) === '' ? null : strtoupper(trim($region));

        $out = [];
        foreach ($productIds as $id) {
            $b = $base[$id] ?? null;
            $row = $rows[$id] ?? null;
            $resolved = $b === null ? null : $this->resolver->resolve(
                $b['price'], $b['roll_price'], $row === null ? null : $this->priceRow($row),
                $currency['code'], $currency['decimals'], $currency['id'] === $default['id'], $currency['exchange_rate'], $at,
            );
            if ($resolved === null) {
                $out[$id] = null;

                continue;
            }

            $rates = $this->ratesFor($snapshot['tax_rates'], (int) ($b['tax_class_id'] ?? $defaultClass), (int) $market['id'], $region, $at);
            $includes = (bool) $market['prices_include_tax'];
            $breakdown = $this->breakdown($resolved->effective, $rates, $includes);
            $roll = $resolved->rollPrice === null ? null : $this->breakdown($resolved->rollPrice, $rates, $includes);

            $out[$id] = [
                'market' => $market['code'], 'currency' => $currency['code'], 'tax_included' => $includes,
                'list_price' => $resolved->list, 'price' => $resolved->effective, 'compare_at' => $resolved->compareAt,
                'on_sale' => $resolved->onSale, 'sale_ends_at' => $resolved->saleEndsAt,
                'price_without_tax' => $breakdown->net, 'price_with_tax' => $breakdown->gross, 'tax_amount' => $breakdown->tax,
                'tax_lines' => array_map(static fn (array $l): array => ['name' => $l['name'], 'rate' => $l['rate'], 'amount' => $l['amount']], $breakdown->lines),
                'roll_price' => $resolved->rollPrice, 'roll_price_with_tax' => $roll?->gross,
            ];
        }

        return $out;
    }

    /** @param list<TaxRate> $rates */
    private function breakdown(Money $price, array $rates, bool $includesTax): TaxBreakdown
    {
        return $includesTax ? $this->tax->fromGross($price, $rates) : $this->tax->fromNet($price, $rates);
    }

    /**
     * @param  list<array<string, mixed>>  $all
     * @return list<TaxRate>
     */
    private function ratesFor(array $all, int $classId, int $marketId, ?string $region, DateTimeImmutable $at): array
    {
        $rates = [];
        foreach ($all as $r) {
            if ($r['tax_class_id'] !== $classId || $r['market_id'] !== $marketId) {
                continue;
            }
            if ($r['region'] !== null && $r['region'] !== $region) {
                continue;
            }
            if (($r['starts_at'] !== null && new DateTimeImmutable($r['starts_at']) > $at) || ($r['ends_at'] !== null && new DateTimeImmutable($r['ends_at']) <= $at)) {
                continue;
            }
            $rates[] = new TaxRate($r['name'], $r['rate'], $r['priority'], $r['is_compound']);
        }

        return $rates;
    }

    /** @param array<string, mixed> $r */
    private function priceRow(array $r): PriceRow
    {
        $date = static fn (?string $v): ?DateTimeImmutable => $v === null ? null : new DateTimeImmutable($v);

        return new PriceRow($r['price'], $r['roll_price'], $r['compare_at_price'], $r['sale_price'], $date($r['sale_starts_at']), $date($r['sale_ends_at']));
    }
}
