<?php

namespace Tests\Feature\Pricing;

use Cronos\Catalog\Application\ProductManager;
use Database\Seeders\PricingSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\GraphQLTestCase;

final class PricingApiTest extends GraphQLTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, PricingSeeder::class]);
        Cache::flush();
    }

    private function product(string $price = '116.00', string $name = 'Moño'): string
    {
        $id = app(ProductManager::class)->create([
            'type' => 'simple', 'sku' => 'P-'.uniqid(), 'price' => $price, 'translations' => ['es-MX' => ['name' => $name]],
        ]);
        app(ProductManager::class)->publish($id);

        return $id;
    }

    public function test_the_storefront_lists_markets_and_prices_products_per_market_in_one_batch(): void
    {
        $a = $this->product();
        $b = $this->product('50.00', 'Lazo');
        $this->gql('{ markets { code pricesIncludeTax currency { code decimals } } }')->assertJsonPath('data.markets.0.code', 'MX')->assertJsonPath('data.markets.1.currency.code', 'USD');

        DB::enableQueryLog();
        $r = $this->gql('{ products(first: 10, sort: NEWEST) { items { id mx: pricing(market: "MX") { price { amount currency } priceWithoutTax { amount } taxAmount { amount } taxIncluded } us: pricing(market: "US") { price { amount currency } taxIncluded } } } }')->assertJsonMissingPath('errors');
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'product_prices') || str_contains($q['query'], 'COALESCE'))->count();

        $items = collect($r->json('data.products.items'))->keyBy('id');
        $this->assertSame(['116.00', 'MXN', '100.00', '16.00', true], [$items[$a]['mx']['price']['amount'], $items[$a]['mx']['price']['currency'], $items[$a]['mx']['priceWithoutTax']['amount'], $items[$a]['mx']['taxAmount']['amount'], $items[$a]['mx']['taxIncluded']]);
        $this->assertSame(['2.75', 'USD'], [$items[$b]['us']['price']['amount'], $items[$b]['us']['price']['currency']]);
        $this->assertLessThanOrEqual(4, $queries, 'two markets x two products must be batched, not one query per product');
    }

    public function test_an_unknown_market_is_a_clean_error(): void
    {
        $this->product();
        $r = $this->gql('{ products(first: 1, sort: NEWEST) { items { pricing(market: "ZZ") { currency } } } }');

        $this->assertSame('NOT_FOUND', $r->json('errors.0.extensions.code'));
    }

    public function test_admin_manages_currencies_markets_taxes_and_per_currency_prices(): void
    {
        $token = $this->adminToken();
        $usd = $this->adminGql('{ adminCurrencies { id code isDefault exchangeRate } }', [], $token)->json('data.adminCurrencies.1.id');

        $this->adminGql('mutation($id: ID!) { setExchangeRate(currencyId: $id, rate: "0.0600") { exchangeRate } }', ['id' => $usd], $token)->assertJsonPath('data.setExchangeRate.exchangeRate', '0.06000000');
        $this->adminGql('mutation($id: ID!) { updateCurrency(id: $id, input: { isActive: false }) { id } }', ['id' => $usd], $token)->assertJsonPath('errors.0.extensions.code', 'CURRENCY_IN_USE');

        $class = $this->adminGql('{ adminTaxClasses { id isDefault } }', [], $token)->json('data.adminTaxClasses.0.id');
        $us = $this->adminGql('{ adminMarkets { id code } }', [], $token)->json('data.adminMarkets.1.id');
        $this->adminGql('mutation($i: TaxRateInput!) { createTaxRate(input: $i) { id region rate } }', ['i' => ['taxClassId' => $class, 'marketId' => $us, 'region' => 'tx', 'name' => 'TX', 'rate' => '6.25']], $token)
            ->assertJsonPath('data.createTaxRate.region', 'TX')->assertJsonPath('data.createTaxRate.rate', '6.25');
        $this->adminGql('mutation($i: TaxRateInput!) { createTaxRate(input: $i) { id } }', ['i' => ['taxClassId' => $class, 'marketId' => $us, 'name' => 'Bad', 'rate' => '150']], $token)->assertJsonPath('errors.0.extensions.code', 'INVALID_PRICING');

        $id = $this->product();
        $set = 'mutation($p: ID!, $c: ID!, $i: ProductPriceInput!) { setProductPrice(productId: $p, currencyId: $c, input: $i) { currencyCode price salePrice saleStartsAt saleEndsAt } }';
        $this->adminGql($set, ['p' => $id, 'c' => $usd, 'i' => ['price' => '7.50', 'salePrice' => '6', 'saleStartsAt' => '2026-01-01 00:00:00', 'saleEndsAt' => '2099-01-01 00:00:00']], $token)
            ->assertJsonMissingPath('errors')->assertJsonPath('data.setProductPrice.currencyCode', 'USD')->assertJsonPath('data.setProductPrice.salePrice', '6')->assertJsonPath('data.setProductPrice.saleEndsAt', '2099-01-01 00:00:00');
        $this->adminGql('query($c: ID!) { adminCurrencyHistory(currencyId: $c) { rate source createdAt } }', ['c' => $usd], $token)->assertJsonMissingPath('errors')->assertJsonPath('data.adminCurrencyHistory.0.source', 'manual');
        $this->adminGql($set, ['p' => $id, 'c' => $usd, 'i' => ['price' => '5', 'salePrice' => '6']], $token)->assertJsonPath('errors.0.extensions.code', 'SALE_NOT_LOWER');

        $this->gql('{ product(slug: "mono") { pricing(market: "US", region: "TX") { price { amount } onSale compareAt { amount } taxAmount { amount } priceWithTax { amount } } } }')
            ->assertJsonPath('data.product.pricing.price.amount', '6.00')->assertJsonPath('data.product.pricing.onSale', true)
            ->assertJsonPath('data.product.pricing.compareAt.amount', '7.50')->assertJsonPath('data.product.pricing.priceWithTax.amount', '6.38');

        $this->adminGql('mutation($p: ID!, $c: ID!) { clearProductPrice(productId: $p, currencyId: $c) }', ['p' => $id, 'c' => $usd], $token)->assertJsonPath('data.clearProductPrice', true);
        $this->adminGql('query($p: ID!) { adminProductPrices(productId: $p) { currencyCode } }', ['p' => $id], $token)->assertJsonPath('data.adminProductPrices', []);
    }

    public function test_support_can_read_pricing_but_not_change_it(): void
    {
        $support = $this->adminToken('help@example.com', 'support');

        $this->adminGql('{ adminCurrencies { code } }', [], $support)->assertJsonMissingPath('errors');
        $this->adminGql('mutation { setExchangeRate(currencyId: 2, rate: "1") { code } }', [], $support)->assertJsonPath('errors.0.message', 'This action is unauthorized.');
    }
}
