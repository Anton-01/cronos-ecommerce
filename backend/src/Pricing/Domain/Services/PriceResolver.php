<?php

declare(strict_types=1);

namespace Cronos\Pricing\Domain\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Cronos\Pricing\Domain\ValueObjects\Money;
use Cronos\Pricing\Domain\ValueObjects\PriceRow;
use Cronos\Pricing\Domain\ValueObjects\ResolvedPrice;
use DateTimeImmutable;

/**
 * One product, one currency, one moment -> the price to show.
 * A regular price set for the currency wins; otherwise the default-currency price is used as-is
 * (when this *is* the default currency) or converted at the current exchange rate.
 * A sale only counts while its window is open and it is actually cheaper.
 */
final class PriceResolver
{
    /**
     * @param  ?string  $basePrice  list price in the default currency (already inherited from the parent for variations)
     * @param  ?string  $baseRollPrice  same, for a whole roll
     * @param  string  $rate  units of $currency per 1 unit of the default currency (1 for the default itself)
     */
    public function resolve(?string $basePrice, ?string $baseRollPrice, ?PriceRow $row, string $currency, int $decimals, bool $isDefaultCurrency, string $rate, DateTimeImmutable $at): ?ResolvedPrice
    {
        $list = $this->amount($row?->price, $basePrice, $currency, $decimals, $isDefaultCurrency, $rate);
        if ($list === null) {
            return null;
        }

        $roll = $this->amount($row?->rollPrice, $baseRollPrice, $currency, $decimals, $isDefaultCurrency, $rate);

        $sale = $row !== null && $row->saleActiveAt($at) ? new Money($row->salePrice, $currency, $decimals) : null;
        $onSale = $sale !== null && $list->isGreaterThan($sale);
        $effective = $onSale ? $sale : $list;

        $compareAt = $row?->compareAt !== null ? new Money($row->compareAt, $currency, $decimals) : null;
        $compareAt = $onSale ? ($compareAt !== null && $compareAt->isGreaterThan($list) ? $compareAt : $list) : ($compareAt !== null && $compareAt->isGreaterThan($effective) ? $compareAt : null);

        return new ResolvedPrice($list, $effective, $compareAt, $onSale, $roll, $onSale ? $row->saleEndsAt : null);
    }

    public function convert(string $amount, string $rate, string $currency, int $decimals): Money
    {
        return new Money(BigDecimal::of($amount)->multipliedBy($rate)->toScale($decimals + 4, RoundingMode::HalfUp), $currency, $decimals);
    }

    private function amount(?string $explicit, ?string $base, string $currency, int $decimals, bool $isDefault, string $rate): ?Money
    {
        if ($explicit !== null) {
            return new Money($explicit, $currency, $decimals);
        }
        if ($base === null) {
            return null;
        }

        return $isDefault ? new Money($base, $currency, $decimals) : $this->convert($base, $rate, $currency, $decimals);
    }
}
