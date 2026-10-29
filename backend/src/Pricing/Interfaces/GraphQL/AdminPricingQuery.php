<?php

declare(strict_types=1);

namespace Cronos\Pricing\Interfaces\GraphQL;

use Cronos\Pricing\Domain\Ports\CurrencyStore;
use Cronos\Pricing\Domain\Ports\MarketStore;
use Cronos\Pricing\Domain\Ports\ProductPriceStore;
use Cronos\Pricing\Domain\Ports\TaxStore;

final class AdminPricingQuery
{
    public function __construct(
        private readonly CurrencyStore $currencies,
        private readonly MarketStore $markets,
        private readonly TaxStore $taxes,
        private readonly ProductPriceStore $prices,
    ) {}

    public function currencies(): array
    {
        return array_map(Present::camel(...), $this->currencies->all());
    }

    /** @param array<string, mixed> $args */
    public function history(mixed $root, array $args): array
    {
        return array_map(Present::camel(...), $this->currencies->history((int) $args['currencyId']));
    }

    public function markets(): array
    {
        return array_map(Present::camel(...), $this->markets->all());
    }

    public function taxClasses(): array
    {
        return array_map(Present::camel(...), $this->taxes->classes());
    }

    /** @param array<string, mixed> $args */
    public function taxRates(mixed $root, array $args): array
    {
        return array_map(Present::camel(...), $this->taxes->rates(isset($args['taxClassId']) ? (int) $args['taxClassId'] : null, isset($args['marketId']) ? (int) $args['marketId'] : null));
    }

    /** @param array<string, mixed> $args */
    public function productPrices(mixed $root, array $args): array
    {
        return array_map(Present::camel(...), $this->prices->forProduct($args['productId']));
    }
}
