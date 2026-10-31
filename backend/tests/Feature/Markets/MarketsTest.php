<?php

namespace Tests\Feature\Markets;

use Cronos\Checkout\Application\CartService;
use Cronos\Checkout\Application\CheckoutService;
use Cronos\Checkout\Domain\Exceptions\CheckoutError;
use Cronos\Inventory\Application\Actor;
use Cronos\Inventory\Application\StockLedger;
use Cronos\Inventory\Domain\Exceptions\InsufficientStock;
use Cronos\Inventory\Domain\Ports\WarehouseRepository;
use Cronos\Inventory\Domain\ValueObjects\MovementType;
use Cronos\Markets\Application\MarketProfileManager;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Orders\OrdersTestCase;

final class MarketsTest extends OrdersTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('geo_ip_ranges')->insert([
            ['ip_from' => '187.190.0.0', 'ip_to' => '187.191.255.255', 'country_code' => 'MX', 'timezone' => null, 'region' => null],
            ['ip_from' => '8.8.0.0', 'ip_to' => '8.8.255.255', 'country_code' => 'US', 'timezone' => null, 'region' => null],
            ['ip_from' => '2001:4860::', 'ip_to' => '2001:4860:ffff:ffff:ffff:ffff:ffff:ffff', 'country_code' => 'US', 'timezone' => null, 'region' => null],
            ['ip_from' => '200.0.0.0', 'ip_to' => '200.0.0.255', 'country_code' => 'PR', 'timezone' => null, 'region' => null],
            ['ip_from' => '201.0.0.0', 'ip_to' => '201.0.0.255', 'country_code' => 'CO', 'timezone' => null, 'region' => null],
        ]);
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function context(string $ip, ?string $selected): array
    {
        $q = 'query($s: String) { marketContext(selected: $s) { detectedCountry outside detectedMarket { code } selectedMarket { code } prompt { title body switchLabel stayLabel } } }';

        return $this->fromIp($ip)->gql($q, ['s' => $selected])->assertJsonMissingPath('errors')->json('data.marketContext');
    }

    public function test_a_mexican_visitor_browsing_the_us_store_is_told_in_spanish_and_offered_the_switch(): void
    {
        $c = $this->context('187.190.10.20', 'US');
        $this->assertTrue($c['outside']);
        $this->assertSame(['MX', 'MX', 'US'], [$c['detectedCountry'], $c['detectedMarket']['code'], $c['selectedMarket']['code']]);
        $this->assertSame('Estás viendo la tienda de United States', $c['prompt']['title']);
        $this->assertSame('Ir a la tienda de México', $c['prompt']['switchLabel']);
        $this->assertSame('Seguir en United States', $c['prompt']['stayLabel']);
    }

    public function test_a_us_visitor_browsing_the_mexican_store_is_told_in_english_over_ipv4_and_ipv6(): void
    {
        foreach (['8.8.8.8', '2001:4860:4860::8888'] as $ip) {
            $c = $this->context($ip, 'MX');
            $this->assertTrue($c['outside'], $ip);
            $this->assertSame('You are browsing the México store', $c['prompt']['title'], $ip);
            $this->assertSame('Go to the United States store', $c['prompt']['switchLabel']);
        }
    }

    public function test_no_notice_when_the_visitor_is_in_the_right_store_or_the_country_is_unknown(): void
    {
        $this->assertFalse($this->context('187.190.10.20', 'MX')['outside']);
        $this->assertFalse($this->context('187.190.10.20', null)['outside'], 'no store chosen yet = the visitor\'s own');
        $this->assertSame('MX', $this->context('187.190.10.20', null)['selectedMarket']['code']);

        $unknown = $this->context('9.9.9.9', 'US');
        $this->assertSame([false, null, null], [$unknown['outside'], $unknown['prompt'], $unknown['detectedCountry']], 'a VPN or private network is never told they are abroad');
        $this->assertFalse($this->context('10.0.0.5', 'US')['outside']);
        $this->assertFalse($this->context('::1', 'US')['outside']);
    }

    public function test_countries_without_a_store_see_the_default_one_and_the_admin_can_link_more_countries_to_a_store(): void
    {
        $admin = $this->adminToken();
        $this->assertSame('MX', $this->context('201.0.0.9', null)['detectedMarket']['code'], 'Colombia has no store: the default (first) one');
        $this->assertFalse($this->context('201.0.0.9', 'US')['outside'], 'and nobody is told they are abroad when no store is theirs');

        $q = 'mutation($c: String!, $l: [String!]!) { setMarketCountries(code: $c, countries: $l) { code countries } }';
        $this->adminGql($q, ['c' => 'US', 'l' => ['US', 'pr']], $admin)->assertJsonPath('data.setMarketCountries.countries', ['PR', 'US']);
        $this->assertSame('US', $this->context('200.0.0.9', 'MX')['detectedMarket']['code']);
        $this->assertTrue($this->context('200.0.0.9', 'MX')['outside']);

        $this->adminGql($q, ['c' => 'MX', 'l' => ['MX', 'PR']], $admin)->assertJsonPath('errors.0.extensions.code', 'COUNTRY_TAKEN');
        $this->adminGql($q, ['c' => 'MX', 'l' => ['CA']], $admin)->assertJsonPath('errors.0.extensions.code', 'OWN_COUNTRY_REQUIRED');
        $this->adminGql($q, ['c' => 'MX', 'l' => ['MX', 'M1']], $admin)->assertJsonPath('errors.0.extensions.code', 'INVALID_COUNTRY');

        $this->adminGql('mutation { setFallbackMarket(code: "US") { code isFallback } }', [], $admin)->assertJsonPath('data.setFallbackMarket.isFallback', true);
        $this->assertSame('US', $this->context('201.0.0.9', null)['detectedMarket']['code']);
        $this->assertSame(1, DB::table('market_profiles')->where('is_fallback', true)->count(), 'only one default store');
    }

    public function test_fiscal_data_is_kept_per_store_validated_by_country_and_audited(): void
    {
        $admin = $this->adminToken();
        $q = 'mutation($c: String!, $i: MarketProfileInput!) { updateMarketProfile(code: $c, input: $i) { code legalName taxId taxIdLabel invoiceSeries contactEmail fiscalAddress { city countryCode } } }';

        $mx = $this->adminGql($q, ['c' => 'MX', 'i' => ['legalName' => 'Listones Cronos SA de CV', 'taxId' => ' lcr 900101 ab1 ', 'invoiceSeries' => 'mx-a', 'contactEmail' => 'VENTAS@cronos.mx', 'fiscalAddress' => ['city' => 'CDMX', 'countryCode' => 'MX']]], $admin)
            ->assertJsonMissingPath('errors')->json('data.updateMarketProfile');
        $this->assertSame(['LCR900101AB1', 'RFC', 'MX-A', 'ventas@cronos.mx', 'CDMX'], [$mx['taxId'], $mx['taxIdLabel'], $mx['invoiceSeries'], $mx['contactEmail'], $mx['fiscalAddress']['city']]);

        $us = $this->adminGql($q, ['c' => 'US', 'i' => ['legalName' => 'Cronos Ribbons LLC', 'taxId' => '123456789']], $admin)->json('data.updateMarketProfile');
        $this->assertSame(['12-3456789', 'EIN'], [$us['taxId'], $us['taxIdLabel']]);
        $this->assertSame('LCR900101AB1', DB::table('market_profiles')->join('markets', 'markets.id', '=', 'market_profiles.market_id')->where('code', 'MX')->value('tax_id'), 'editing one store never touches the other');

        foreach ([['MX', '123'], ['MX', '12-3456789'], ['US', 'LCR900101AB1'], ['US', '12-345']] as [$market, $bad]) {
            $this->adminGql($q, ['c' => $market, 'i' => ['taxId' => $bad]], $admin)->assertJsonPath('errors.0.extensions.code', 'INVALID_TAX_ID');
        }
        $this->adminGql($q, ['c' => 'MX', 'i' => ['contactEmail' => 'nope']], $admin)->assertJsonPath('errors.0.extensions.code', 'INVALID_EMAIL');
        $this->assertGreaterThan(0, DB::table('audits')->where('auditable_type', 'like', '%MarketProfileModel')->count(), 'every change is in the audit trail');

        $support = $this->adminToken('help@example.com', 'support');
        $this->adminGql('{ adminMarketProfiles { code } }', [], $support)->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'unauthorized'));
        $this->adminGql('{ adminMarketProfiles { code taxId warehouses { code } paymentProviders taxRateCount } }', [], $admin)->assertJsonCount(2, 'data.adminMarketProfiles');
    }

    public function test_the_store_tells_customers_who_sells_and_how_to_reach_them(): void
    {
        app(MarketProfileManager::class)->update('US', ['legal_name' => 'Cronos Ribbons LLC', 'tax_id' => '12-3456789', 'contact_phone' => '+1 555 0100', 'support_hours' => 'Mon-Fri 9-5 CT']);

        $r = $this->gql('{ markets { code profile { legalName taxIdLabel taxId contactPhone supportHours countries } } }')->assertJsonMissingPath('errors')->json('data.markets');
        $us = collect($r)->firstWhere('code', 'US')['profile'];
        $this->assertSame(['Cronos Ribbons LLC', 'EIN', '12-3456789', '+1 555 0100', 'Mon-Fri 9-5 CT', ['US']], [$us['legalName'], $us['taxIdLabel'], $us['taxId'], $us['contactPhone'], $us['supportHours'], $us['countries']]);
        $this->assertNull(collect($r)->firstWhere('code', 'MX')['profile']['legalName']);
    }

    public function test_shipping_comes_from_each_stores_profile_and_the_order_freezes_the_sellers_identity(): void
    {
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 20);
        app(MarketProfileManager::class)->update('MX', ['legal_name' => 'Listones Cronos SA de CV', 'tax_id' => 'LCR900101AB1', 'invoice_series' => 'MX-A', 'shipping_flat' => '99', 'shipping_free_over' => '10000']);

        $order = $this->placeOrder($bow, '2');
        $this->assertSame('99.00', $order['shipping_total'], 'the profile\'s flat fee, not the old config');
        $issuer = json_decode($order['issuer'], true);
        $this->assertSame(['MX', 'Listones Cronos SA de CV', 'LCR900101AB1', 'MX-A'], [$issuer['market'], $issuer['legal_name'], $issuer['tax_id'], $issuer['invoice_series']]);

        app(MarketProfileManager::class)->update('MX', ['legal_name' => 'Otra Razón Social SA']);
        $this->assertSame('Listones Cronos SA de CV', json_decode(DB::table('orders')->where('id', $order['id'])->value('issuer'), true)['legal_name'], 'a later edit never rewrites a sold order');
        $this->gql('query($n: String!) { trackOrder(number: $n, email: "ana@example.com") { issuer } }', ['n' => $order['order_number']])->assertJsonPath('data.trackOrder.issuer.tax_id', 'LCR900101AB1');
    }

    public function test_the_customers_tax_id_for_the_invoice_is_checked_and_kept_with_the_billing_address(): void
    {
        $bow = $this->stocked($this->published($this->piece()), 20);
        $cart = app(CartService::class)->resolve(null, null, 'MX')['cart']['id'];
        app(CartService::class)->setItem($cart, $bow, '1', false);

        try {
            app(CheckoutService::class)->start($cart, null, 'a@b.co', self::ADDRESS, self::ADDRESS + ['tax_id' => 'bad'], 'k-bad');
            $this->fail('invalid RFC accepted');
        } catch (CheckoutError $e) {
            $this->assertSame('INVALID_TAX_ID', $e->errorCode());
        }
        $c = app(CheckoutService::class)->start($cart, null, 'a@b.co', self::ADDRESS, self::ADDRESS + ['tax_id' => 'xaxx 010101 000', 'business_name' => 'Público en General'], 'k-ok');
        $this->assertSame(['XAXX010101000', 'Público en General'], [$c['billing_address']['tax_id'], $c['billing_address']['business_name']]);
    }

    public function test_a_stores_stock_is_its_own_for_availability_and_for_reservations(): void
    {
        $usWarehouse = collect(app(WarehouseRepository::class)->all())->firstWhere('code', 'US-01')['id'];
        $onlyUs = $this->published($this->piece('Moño US', 'MONO-US'));
        app(StockLedger::class)->record($onlyUs, $usWarehouse, MovementType::PURCHASE, 10, Actor::system(), unitCost: '5.00');
        $q = 'query($m: String!) { products { items { sku } } }';

        $status = fn (string $market) => $this->gql('query($m: String!) { product(slug: "mono-us", locale: "es-MX") { stockStatus stockStatusIn(market: $m) } }', ['m' => $market])->json('data.product');
        $this->assertSame(['IN_STOCK', 'OUT_OF_STOCK'], [$status('MX')['stockStatus'], $status('MX')['stockStatusIn']], 'in stock somewhere, but not for Mexico');
        $this->assertSame('IN_STOCK', $status('US')['stockStatusIn']);

        $cart = app(CartService::class)->resolve(null, null, 'MX')['cart']['id'];
        app(CartService::class)->setItem($cart, $onlyUs, '1', false);
        $this->expectException(InsufficientStock::class);
        app(CheckoutService::class)->start($cart, null, 'a@b.co', self::ADDRESS, null, 'k-mx');
    }
}
