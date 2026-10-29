<?php

declare(strict_types=1);

namespace Cronos\Pricing\Interfaces\GraphQL;

use Cronos\Pricing\Application\CurrencyManager;
use Cronos\Pricing\Application\ExchangeRateUpdater;
use Cronos\Pricing\Application\MarketManager;
use Cronos\Pricing\Application\ProductPriceManager;
use Cronos\Pricing\Application\TaxManager;
use Cronos\Pricing\Domain\Ports\CurrencyStore;
use Cronos\Pricing\Domain\Ports\MarketStore;
use Cronos\Pricing\Domain\Ports\ProductPriceStore;
use Cronos\Pricing\Domain\Ports\TaxStore;

final class AdminPricingMutations
{
    public function __construct(
        private readonly CurrencyManager $currencies,
        private readonly CurrencyStore $currencyStore,
        private readonly ExchangeRateUpdater $updater,
        private readonly MarketManager $markets,
        private readonly MarketStore $marketStore,
        private readonly TaxManager $taxes,
        private readonly TaxStore $taxStore,
        private readonly ProductPriceManager $prices,
        private readonly ProductPriceStore $priceStore,
    ) {}

    /** @param array<string, mixed> $args */
    public function createCurrency(mixed $root, array $args): array
    {
        return $this->currency($this->currencies->create(Present::snake($args['input'])));
    }

    /** @param array<string, mixed> $args */
    public function updateCurrency(mixed $root, array $args): array
    {
        $this->currencies->update((int) $args['id'], Present::snake($args['input']));

        return $this->currency((int) $args['id']);
    }

    /** @param array<string, mixed> $args */
    public function setRate(mixed $root, array $args): array
    {
        $this->currencies->setRate((int) $args['currencyId'], $args['rate']);

        return $this->currency((int) $args['currencyId']);
    }

    public function refreshRates(): array
    {
        $report = $this->updater->run((float) config('services.exchange_rates.max_change_percent', 25));

        return [
            'updated' => array_map(fn ($code, $rate) => ['code' => $code, 'rate' => $rate], array_keys($report['updated']), $report['updated']),
            'skipped' => array_map(fn ($code, $reason) => ['code' => $code, 'reason' => $reason], array_keys($report['skipped']), $report['skipped']),
        ];
    }

    /** @param array<string, mixed> $args */
    public function createMarket(mixed $root, array $args): array
    {
        return $this->market($this->markets->create(Present::snake($args['input'])));
    }

    /** @param array<string, mixed> $args */
    public function updateMarket(mixed $root, array $args): array
    {
        $this->markets->update((int) $args['id'], Present::snake($args['input']));

        return $this->market((int) $args['id']);
    }

    /** @param array<string, mixed> $args */
    public function deleteMarket(mixed $root, array $args): bool
    {
        $this->markets->delete((int) $args['id']);

        return true;
    }

    /** @param array<string, mixed> $args */
    public function createTaxClass(mixed $root, array $args): array
    {
        return $this->taxClass($this->taxes->createClass($args['name'], (bool) ($args['isDefault'] ?? false)));
    }

    /** @param array<string, mixed> $args */
    public function updateTaxClass(mixed $root, array $args): array
    {
        $this->taxes->updateClass((int) $args['id'], $args['name'] ?? null, $args['isDefault'] ?? null);

        return $this->taxClass((int) $args['id']);
    }

    /** @param array<string, mixed> $args */
    public function deleteTaxClass(mixed $root, array $args): bool
    {
        $this->taxes->deleteClass((int) $args['id']);

        return true;
    }

    /** @param array<string, mixed> $args */
    public function createTaxRate(mixed $root, array $args): array
    {
        return $this->taxRate($this->taxes->createRate(Present::snake($args['input'])));
    }

    /** @param array<string, mixed> $args */
    public function updateTaxRate(mixed $root, array $args): array
    {
        $this->taxes->updateRate((int) $args['id'], Present::snake($args['input']));

        return $this->taxRate((int) $args['id']);
    }

    /** @param array<string, mixed> $args */
    public function deleteTaxRate(mixed $root, array $args): bool
    {
        $this->taxes->deleteRate((int) $args['id']);

        return true;
    }

    /** @param array<string, mixed> $args */
    public function setProductPrice(mixed $root, array $args): array
    {
        $input = Present::snake($args['input']);
        $input['compare_at_price'] = $input['compare_at_price'] ?? null;
        $this->prices->set($args['productId'], (int) $args['currencyId'], $input);

        return Present::camel(collect($this->priceStore->forProduct($args['productId']))->firstWhere('currency_id', (int) $args['currencyId']));
    }

    /** @param array<string, mixed> $args */
    public function clearProductPrice(mixed $root, array $args): bool
    {
        $this->prices->clear($args['productId'], (int) $args['currencyId']);

        return true;
    }

    private function currency(int $id): array
    {
        return Present::camel($this->currencyStore->find($id));
    }

    private function market(int $id): array
    {
        return Present::camel($this->marketStore->find($id));
    }

    private function taxClass(int $id): array
    {
        return Present::camel($this->taxStore->findClass($id));
    }

    private function taxRate(int $id): array
    {
        return Present::camel($this->taxStore->findRate($id));
    }
}
