<?php

namespace Database\Seeders;

use Cronos\Pricing\Application\CurrencyManager;
use Cronos\Pricing\Application\MarketManager;
use Cronos\Pricing\Application\TaxManager;
use Cronos\Pricing\Domain\Ports\CurrencyStore;
use Cronos\Pricing\Domain\Ports\MarketStore;
use Cronos\Pricing\Domain\Ports\TaxStore;
use Illuminate\Database\Seeder;

/**
 * Mexico (MXN, IVA included in shelf prices) and the United States (USD, sales tax added at checkout).
 * Idempotent. US state rates are left to the back-office: they depend on where the shop has nexus.
 * The USD rate is a placeholder until `pricing:update-rates` runs.
 */
class PricingSeeder extends Seeder
{
    public function run(CurrencyStore $currencies, MarketStore $markets, TaxStore $taxes, CurrencyManager $currencyManager, MarketManager $marketManager, TaxManager $taxManager): void
    {
        $mxn = $currencies->findByCode('MXN')['id'] ?? $currencyManager->create(['code' => 'MXN', 'name' => 'Peso mexicano', 'symbol' => '$']);
        $usd = $currencies->findByCode('USD')['id'] ?? $currencyManager->create(['code' => 'USD', 'name' => 'US dollar', 'symbol' => 'US$', 'exchange_rate' => '0.05500000']);

        $classId = collect($taxes->classes())->firstWhere('is_default', true)['id'] ?? $taxManager->createClass('Estándar', true);

        $mx = collect($markets->all())->firstWhere('code', 'MX')['id'] ?? $marketManager->create([
            'code' => 'MX', 'name' => 'México', 'country_code' => 'MX', 'currency_id' => $mxn, 'default_locale' => 'es-MX', 'prices_include_tax' => true,
        ]);
        collect($markets->all())->firstWhere('code', 'US') ?? $marketManager->create([
            'code' => 'US', 'name' => 'United States', 'country_code' => 'US', 'currency_id' => $usd, 'default_locale' => 'en-US', 'prices_include_tax' => false,
        ]);

        $taxes->rates($classId, $mx) !== [] || $taxManager->createRate(['tax_class_id' => $classId, 'market_id' => $mx, 'name' => 'IVA', 'rate' => '16']);
    }
}
