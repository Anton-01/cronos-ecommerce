<?php

namespace Tests\Feature\Pricing;

use Cronos\Catalog\Application\CategoryManager;
use Cronos\Pricing\Application\CurrencyManager;
use Cronos\Pricing\Application\ExchangeRateUpdater;
use Cronos\Pricing\Application\MarketManager;
use Cronos\Pricing\Application\PriceQuoter;
use Cronos\Pricing\Application\ProductPriceManager;
use Cronos\Pricing\Application\TaxManager;
use Cronos\Pricing\Domain\Exceptions\Conflict;
use Cronos\Pricing\Domain\Exceptions\InvalidPricing;
use Cronos\Pricing\Domain\Exceptions\NotFound;
use Cronos\Pricing\Domain\Ports\CurrencyStore;
use Cronos\Pricing\Domain\Ports\ExchangeRateProvider;
use Cronos\Pricing\Domain\Ports\MarketStore;
use Cronos\Pricing\Domain\Ports\PricingCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PricingServicesTest extends PricingTestCase
{
    public function test_the_seed_gives_mexico_and_the_us_with_mxn_as_the_default_currency(): void
    {
        $currencies = collect(app(CurrencyStore::class)->all())->keyBy('code');

        $this->assertTrue($currencies['MXN']['is_default']);
        $this->assertSame('1.00000000', $currencies['MXN']['exchange_rate']);
        $this->assertFalse($currencies['USD']['is_default']);
        $this->assertSame(['MX', 'US'], collect(app(PricingCatalog::class)->snapshot()['markets'])->pluck('code')->sort()->values()->all());
        $this->artisan('db:seed', ['--class' => 'PricingSeeder'])->assertSuccessful();
        $this->assertCount(2, app(CurrencyStore::class)->all(), 'the seeder is idempotent');
    }

    public function test_the_default_currency_is_protected_and_codes_are_validated(): void
    {
        $currencies = app(CurrencyManager::class);
        $mxn = $this->currencyId('MXN');

        foreach ([
            fn () => $currencies->update($mxn, ['is_active' => false]),
            fn () => $currencies->setRate($mxn, '2'),
            fn () => $currencies->create(['code' => 'mxn', 'name' => 'dup', 'symbol' => '$', 'exchange_rate' => '1']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('expected a domain error');
            } catch (Conflict) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidPricing::class);
        $currencies->create(['code' => 'EURO', 'name' => 'x', 'symbol' => '€', 'exchange_rate' => '0.05']);
    }

    public function test_every_rate_change_is_appended_to_an_immutable_history(): void
    {
        $usd = $this->currencyId('USD');
        app(CurrencyManager::class)->setRate($usd, '0.0570', 'manual');

        $history = app(CurrencyStore::class)->history($usd);
        $this->assertSame(['0.05700000', 'initial'], [$history[0]['rate'], $history[1]['source']]);

        $this->expectException(QueryException::class);
        DB::table('exchange_rate_history')->update(['rate' => 9]);
    }

    public function test_a_currency_sold_in_by_an_active_market_cannot_be_deactivated_or_change_its_decimals(): void
    {
        $usd = $this->currencyId('USD');
        $currencies = app(CurrencyManager::class);

        try {
            $currencies->update($usd, ['is_active' => false]); // the US market sells in it
            $this->fail('expected a conflict');
        } catch (Conflict $e) {
            $this->assertSame('CURRENCY_IN_USE', $e->errorCode());
        }

        $this->piece(price: '10.00');
        $product = DB::table('products')->value('id');
        app(ProductPriceManager::class)->set($product, $usd, ['price' => '1']);
        $this->expectException(Conflict::class);
        $currencies->update($usd, ['decimals' => 3]);
    }

    public function test_markets_need_an_active_currency_a_supported_locale_and_a_unique_code(): void
    {
        $markets = app(MarketManager::class);
        $usd = $this->currencyId('USD');

        $id = $markets->create(['code' => 'ca', 'name' => 'Canada', 'country_code' => 'ca', 'currency_id' => $usd, 'default_locale' => 'en-US']);
        $this->assertSame('CA', app(MarketStore::class)->find($id)['code']);

        $this->expectException(Conflict::class);
        $markets->create(['code' => 'CA', 'name' => 'dup', 'country_code' => 'CA', 'currency_id' => $usd]);
    }

    public function test_the_mexican_price_shows_iva_inside_and_the_us_price_adds_sales_tax_by_state(): void
    {
        $taxes = app(TaxManager::class);
        $us = $this->marketId('US');
        $class = $this->defaultTaxClassId();
        $taxes->createRate(['tax_class_id' => $class, 'market_id' => $us, 'region' => 'CA', 'name' => 'CA state', 'rate' => '7.25']);
        $taxes->createRate(['tax_class_id' => $class, 'market_id' => $us, 'region' => 'CA', 'name' => 'LA county', 'rate' => '2.25', 'priority' => 1]);
        $taxes->createRate(['tax_class_id' => $class, 'market_id' => $us, 'region' => 'OR', 'name' => 'Oregon', 'rate' => '0']);

        $id = $this->published($this->piece(price: '116.00'));
        $quoter = app(PriceQuoter::class);

        $mx = $quoter->quote([$id], 'MX')[$id];
        $this->assertSame(['MXN', true, '116.00', '100.00', '16.00'], [$mx['currency'], $mx['tax_included'], $mx['price_with_tax']->value(), $mx['price_without_tax']->value(), $mx['tax_amount']->value()]);

        $ca = $quoter->quote([$id], 'US', 'ca')[$id];
        $this->assertSame(['USD', false, '6.38', '0.60', '6.98'], [$ca['currency'], $ca['tax_included'], $ca['price']->value(), $ca['tax_amount']->value(), $ca['price_with_tax']->value()], '116 MXN x 0.055 = 6.38 + 7.25 % and 2.25 % taxes, each rounded');
        $this->assertSame(['CA state', 'LA county'], array_column($ca['tax_lines'], 'name'));

        $none = $quoter->quote([$id], 'US', 'TX')[$id];
        $this->assertSame('0.00', $none['tax_amount']->value(), 'no rate for that state = no tax');
    }

    public function test_a_price_set_for_usd_beats_conversion_and_a_scheduled_sale_applies_only_inside_its_window(): void
    {
        $id = $this->published($this->piece(price: '200.00'));
        $prices = app(ProductPriceManager::class);
        $usd = $this->currencyId('USD');
        $quoter = app(PriceQuoter::class);

        $this->assertSame('11.00', $quoter->quote([$id], 'US')[$id]['price']->value());

        $prices->set($id, $usd, ['price' => '12.50', 'sale_price' => '9.90', 'sale_starts_at' => '2026-12-01 00:00:00', 'sale_ends_at' => '2026-12-31 00:00:00', 'compare_at_price' => '15']);
        $inside = $quoter->quote([$id], 'US', null, new \DateTimeImmutable('2026-12-15'))[$id];
        $outside = $quoter->quote([$id], 'US', null, new \DateTimeImmutable('2027-01-02'))[$id];

        $this->assertSame([true, '9.90', '15.00'], [$inside['on_sale'], $inside['price']->value(), $inside['compare_at']->value()]);
        $this->assertSame([false, '12.50', '15.00'], [$outside['on_sale'], $outside['price']->value(), $outside['compare_at']->value()]);
        $this->assertSame('200.00', $quoter->quote([$id], 'MX')[$id]['price']->value(), 'the MXN price is untouched');
    }

    public function test_variations_quote_with_the_inherited_price_and_ribbon_rolls_have_their_own_price(): void
    {
        $a = $this->colorAndWidth();
        $parent = $this->variableRibbon($a, price: '10.00');
        $generated = $this->variations()->generate($parent);
        $variation = $generated[0] ?? ($generated['variations'][0] ?? null);
        $variationId = is_array($variation) ? ($variation['id'] ?? $variation) : $variation;
        $ribbon = $this->published($this->ribbon());

        $quoter = app(PriceQuoter::class);
        $this->assertSame('10.00', $quoter->quote([$variationId], 'MX')[$variationId]['price']->value());

        $q = $quoter->quote([$ribbon], 'MX')[$ribbon];
        $this->assertSame(['18.00', '400.00'], [$q['price']->value(), $q['roll_price']->value()]);
        $us = $quoter->quote([$ribbon], 'US')[$ribbon];
        $this->assertSame(['0.99', '22.00'], [$us['price']->value(), $us['roll_price']->value()]);
    }

    public function test_a_product_without_a_price_is_not_for_sale_and_an_unknown_market_is_refused(): void
    {
        $id = $this->products()->create(['type' => 'simple', 'sku' => 'NOPRICE', 'translations' => ['es-MX' => ['name' => 'Sin precio']]]);

        $this->assertNull(app(PriceQuoter::class)->quote([$id], 'MX')[$id]);
        $this->expectException(NotFound::class);
        app(PriceQuoter::class)->quote([$id], 'ZZ');
    }

    public function test_the_reference_data_snapshot_is_cached_and_dropped_on_every_write(): void
    {
        $catalog = app(PricingCatalog::class);
        $catalog->snapshot();

        DB::enableQueryLog();
        $catalog->snapshot();
        $this->assertSame([], DB::getQueryLog(), 'a warm snapshot costs no queries');

        app(CurrencyManager::class)->setRate($this->currencyId('USD'), '0.06');
        $this->assertSame('0.06000000', collect($catalog->snapshot()['currencies'])->firstWhere('code', 'USD')['exchange_rate']);
    }

    public function test_the_exchange_rate_job_updates_rates_records_history_and_refuses_glitches(): void
    {
        $this->app->bind(ExchangeRateProvider::class, fn () => new class implements ExchangeRateProvider
        {
            public function name(): string
            {
                return 'fake';
            }

            public function fetch(string $base, array $targets): array
            {
                return ['USD' => '0.05800000'];
            }
        });

        $report = app(ExchangeRateUpdater::class)->run();
        $this->assertSame(['USD' => '0.05800000'], $report['updated']);
        $this->assertSame('fake', app(CurrencyStore::class)->history($this->currencyId('USD'))[0]['source']);

        app(CurrencyManager::class)->setRate($this->currencyId('USD'), '0.0100');
        $glitch = app(ExchangeRateUpdater::class)->run();
        $this->assertSame([], $glitch['updated']);
        $this->assertStringContainsString('limit', $glitch['skipped']['USD']);
    }

    public function test_sale_price_must_be_lower_and_windows_must_make_sense(): void
    {
        $id = $this->piece();
        $prices = app(ProductPriceManager::class);

        $this->expectException(InvalidPricing::class);
        $prices->set($id, $this->currencyId('USD'), ['price' => '10', 'sale_price' => '10']);
    }

    public function test_a_tax_class_in_use_by_products_cannot_be_deleted(): void
    {
        $taxes = app(TaxManager::class);
        $extra = $taxes->createClass('Reducido');
        $this->piece(extra: ['tax_class_id' => $extra]);

        $this->expectException(Conflict::class);
        $taxes->deleteClass($extra);
    }

    public function test_a_down_rate_feed_fails_the_command_loudly_and_keeps_the_last_known_rate(): void
    {
        $this->app->bind(ExchangeRateProvider::class, fn () => new class implements ExchangeRateProvider
        {
            public function name(): string
            {
                return 'down';
            }

            public function fetch(string $base, array $targets): array
            {
                throw new \RuntimeException('connection refused');
            }
        });
        Log::spy();

        $this->artisan('pricing:update-rates')->assertFailed();

        Log::shouldHaveReceived('critical')->once();
        $this->assertSame('0.05500000', app(CurrencyStore::class)->findByCode('USD')['exchange_rate']);
    }

    public function test_a_product_takes_its_categorys_tax_class_unless_it_has_its_own(): void
    {
        $taxes = app(TaxManager::class);
        $mx = $this->marketId('MX');
        $zero = $taxes->createClass('Tasa 0');
        $taxes->createRate(['tax_class_id' => $zero, 'market_id' => $mx, 'name' => 'IVA 0', 'rate' => '0']);
        $food = $this->category('Alimentos');
        app(CategoryManager::class)->update($food, ['default_tax_class_id' => $zero]);

        $inCategory = $this->piece(name: 'En categoría', sku: 'IN-CAT', price: '100.00', extra: ['category_ids' => [$food], 'primary_category_id' => $food]);
        $own = $this->piece(name: 'Propio', sku: 'OWN', price: '100.00', extra: ['category_ids' => [$food], 'primary_category_id' => $food, 'tax_class_id' => $this->defaultTaxClassId()]);
        $plain = $this->piece(name: 'Plano', sku: 'PLAIN', price: '100.00');

        $q = app(PriceQuoter::class)->quote([$inCategory, $own, $plain], 'MX');

        $this->assertSame('0.00', $q[$inCategory]['tax_amount']->value(), 'category tax class: 0 % IVA');
        $this->assertSame('13.79', $q[$own]['tax_amount']->value(), 'its own (default, 16 %) class wins; 100 includes tax');
        $this->assertSame('13.79', $q[$plain]['tax_amount']->value());
    }
}
