<?php

namespace Tests\Feature\Pricing;

use Database\Seeders\PricingSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Catalog\CatalogTestCase;

/** Catalogue helpers plus the Mexico/US reference data (MXN default, USD at 0.055, IVA 16 %). */
abstract class PricingTestCase extends CatalogTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, PricingSeeder::class]);
        Cache::flush();
    }

    protected function currencyId(string $code): int
    {
        return app(\Cronos\Pricing\Domain\Ports\CurrencyStore::class)->findByCode($code)['id'];
    }

    protected function marketId(string $code): int
    {
        return collect(app(\Cronos\Pricing\Domain\Ports\MarketStore::class)->all())->firstWhere('code', $code)['id'];
    }

    protected function defaultTaxClassId(): int
    {
        return collect(app(\Cronos\Pricing\Domain\Ports\TaxStore::class)->classes())->firstWhere('is_default', true)['id'];
    }
}
