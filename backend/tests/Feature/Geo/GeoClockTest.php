<?php

namespace Tests\Feature\Geo;

use Carbon\Carbon;
use Cronos\Catalog\Application\ProductManager;
use Cronos\Fraud\Application\FraudService;
use Cronos\Fraud\Domain\ValueObjects\FraudContext;
use Cronos\Pricing\Domain\ValueObjects\Money;
use Cronos\Shared\Domain\Geo\GeoLocation;
use Cronos\Shared\Domain\Geo\GeoLocator;
use Cronos\Shared\Infrastructure\Geo\CurrentClient;
use Database\Seeders\FraudRulesSeeder;
use Database\Seeders\PricingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\GraphQLTestCase;

final class GeoClockTest extends GraphQLTestCase
{
    use RefreshDatabase;

    private const MX_IP = '187.190.10.20';

    private const US_IP = '8.8.8.8';

    private const UNKNOWN_IP = '1.1.1.1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PricingSeeder::class, FraudRulesSeeder::class]);
        Cache::flush();

        $csv = tempnam(sys_get_temp_dir(), 'geo');
        file_put_contents($csv, implode("\n", [
            'from,to,country,timezone',
            '187.190.0.0,187.191.255.255,MX,',
            '8.8.0.0,8.8.255.255,US,America/Los_Angeles',
            '2001:4860::,2001:4860:ffff:ffff:ffff:ffff:ffff:ffff,US,America/Chicago',
            'not-an-ip,x,ZZ,',
        ]));
        $this->artisan('geo:import', ['file' => $csv])->assertSuccessful();
        unlink($csv);
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    public function test_the_import_loads_valid_ranges_and_skips_garbage(): void
    {
        $this->assertSame(3, DB::table('geo_ip_ranges')->count());
        $this->artisan('geo:import', ['file' => '/nonexistent.csv'])->assertFailed();
    }

    public function test_a_visitor_in_mexico_gets_the_mexico_city_clock_and_the_mx_market(): void
    {
        $r = $this->fromIp(self::MX_IP)->gql('{ clientContext { ip country timezone utcOffset source suggestedMarket } }')->assertJsonMissingPath('errors');

        $this->assertSame(['ip' => self::MX_IP, 'country' => 'MX', 'timezone' => 'America/Mexico_City', 'utcOffset' => '-06:00', 'source' => 'ranges', 'suggestedMarket' => 'MX'], $r->json('data.clientContext'));
    }

    public function test_a_visitor_in_the_us_gets_their_regions_clock_from_the_range_and_the_us_market(): void
    {
        $r = $this->fromIp(self::US_IP)->gql('{ clientContext { country timezone suggestedMarket } }');
        $this->assertSame(['country' => 'US', 'timezone' => 'America/Los_Angeles', 'suggestedMarket' => 'US'], $r->json('data.clientContext'));

        $v6 = $this->fromIp('2001:4860:4860::8888')->gql('{ clientContext { country timezone } }');
        $this->assertSame(['country' => 'US', 'timezone' => 'America/Chicago'], $v6->json('data.clientContext'));
    }

    public function test_unknown_and_private_addresses_fall_back_to_the_configured_clock_without_a_country(): void
    {
        config(['geo.default_timezone' => 'America/Mexico_City']);

        foreach ([self::UNKNOWN_IP, '10.1.2.3', '127.0.0.1'] as $ip) {
            $c = $this->fromIp($ip)->gql('{ clientContext { country timezone source suggestedMarket } }')->json('data.clientContext');
            $this->assertSame([null, 'America/Mexico_City', 'fallback', null], [$c['country'], $c['timezone'], $c['source'], $c['suggestedMarket']], $ip);
        }
    }

    public function test_the_country_header_of_a_trusted_cdn_fills_in_when_the_ranges_do_not_know_the_ip(): void
    {
        config(['geo.country_header' => 'CF-IPCountry']);
        $headers = ['CF-IPCountry' => 'MX'];

        $untrusted = $this->fromIp(self::UNKNOWN_IP)->withHeaders($headers)->gql('{ clientContext { country source } }')->json('data.clientContext');
        $this->assertSame([null, 'fallback'], [$untrusted['country'], $untrusted['source']], 'anyone could send that header; it is ignored without a trusted proxy');

        TrustProxies::at('*');
        try {
            $trusted = $this->fromIp('10.0.0.5')->withHeaders($headers + ['X-Forwarded-For' => self::UNKNOWN_IP])->gql('{ clientContext { ip country timezone source } }')->json('data.clientContext');
        } finally {
            TrustProxies::flushState();
            Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        }
        $this->assertSame([self::UNKNOWN_IP, 'MX', 'America/Mexico_City', 'header'], [$trusted['ip'], $trusted['country'], $trusted['timezone'], $trusted['source']]);

    }

    private function productWithSale(string $token): string
    {
        $id = app(ProductManager::class)->create(['type' => 'simple', 'sku' => 'GEO-1', 'price' => '100.00', 'translations' => ['es-MX' => ['name' => 'Reloj']]]);
        app(ProductManager::class)->publish($id);
        $usd = DB::table('currencies')->where('code', 'USD')->value('id');
        $set = 'mutation($p: ID!, $c: ID!, $i: ProductPriceInput!) { setProductPrice(productId: $p, currencyId: $c, input: $i) { saleStartsAt saleEndsAt } }';
        $this->fromIp(self::MX_IP)->adminGql($set, ['p' => $id, 'c' => $usd, 'i' => ['price' => '10', 'salePrice' => '8', 'saleStartsAt' => '2030-06-01 00:00:00', 'saleEndsAt' => '2030-06-10 12:00']], $token)->assertJsonMissingPath('errors');

        return $id;
    }

    public function test_naive_dates_are_read_in_the_callers_clock_stored_as_utc_and_shown_back_in_the_viewers_clock(): void
    {
        $token = $this->adminToken();
        $id = $this->productWithSale($token);

        $row = DB::table('product_prices')->where('product_id', $id)->first();
        $this->assertSame(['2030-06-01 06:00:00+00', '2030-06-10 18:00:00+00'], [$row->sale_starts_at, $row->sale_ends_at], 'Mexico City midnight = 06:00 UTC (stored in UTC)');

        $q = 'query($p: ID!) { adminProductPrices(productId: $p) { saleStartsAt saleEndsAt } }';
        $mx = $this->fromIp(self::MX_IP)->adminGql($q, ['p' => $id], $token)->json('data.adminProductPrices.0');
        $us = $this->fromIp(self::US_IP)->adminGql($q, ['p' => $id], $token)->json('data.adminProductPrices.0');

        $this->assertSame(['2030-06-01T00:00:00-06:00', '2030-06-10T12:00:00-06:00'], [$mx['saleStartsAt'], $mx['saleEndsAt']]);
        $this->assertSame(['2030-05-31T23:00:00-07:00', '2030-06-10T11:00:00-07:00'], [$us['saleStartsAt'], $us['saleEndsAt']], 'same instants on a Los Angeles clock (PDT)');
    }

    public function test_input_with_an_explicit_offset_is_that_exact_instant_whatever_the_callers_clock(): void
    {
        $token = $this->adminToken();
        $id = app(ProductManager::class)->create(['type' => 'simple', 'sku' => 'GEO-2', 'price' => '100.00', 'translations' => ['es-MX' => ['name' => 'Cinta']]]);
        $usd = DB::table('currencies')->where('code', 'USD')->value('id');
        $set = 'mutation($p: ID!, $c: ID!, $i: ProductPriceInput!) { setProductPrice(productId: $p, currencyId: $c, input: $i) { saleEndsAt } }';

        $this->fromIp(self::US_IP)->adminGql($set, ['p' => $id, 'c' => $usd, 'i' => ['price' => '10', 'salePrice' => '8', 'saleEndsAt' => '2030-06-10T12:00:00+02:00']], $token)->assertJsonMissingPath('errors');

        $this->assertSame('2030-06-10 10:00:00+00', DB::table('product_prices')->where('product_id', $id)->value('sale_ends_at'));
    }

    public function test_a_malformed_date_is_a_clean_validation_error(): void
    {
        $token = $this->adminToken();
        $id = app(ProductManager::class)->create(['type' => 'simple', 'sku' => 'GEO-3', 'price' => '100.00', 'translations' => ['es-MX' => ['name' => 'Hilo']]]);
        $usd = DB::table('currencies')->where('code', 'USD')->value('id');

        $r = $this->adminGql('mutation($p: ID!, $c: ID!, $i: ProductPriceInput!) { setProductPrice(productId: $p, currencyId: $c, input: $i) { currencyId } }', ['p' => $id, 'c' => $usd, 'i' => ['price' => '10', 'saleEndsAt' => 'mañana']], $token);

        $this->assertStringContainsString('not a valid date', $r->json('errors.0.message'));
    }

    public function test_every_audit_row_records_the_callers_country_and_clock(): void
    {
        $token = $this->adminToken();
        $this->fromIp(self::MX_IP)->adminGql('mutation { createTaxClass(name: "Reducida") { id } }', [], $token)->assertJsonMissingPath('errors');
        $this->fromIp(self::US_IP)->adminGql('mutation { createTaxClass(name: "Exenta") { id } }', [], $token)->assertJsonMissingPath('errors');

        $rows = DB::table('audits')->where('auditable_type', 'like', '%TaxClassModel')->orderByDesc('id')->limit(2)->get(['country_code', 'timezone', 'ip_address'])->reverse()->values();
        $this->assertSame([['MX', 'America/Mexico_City', self::MX_IP], ['US', 'America/Los_Angeles', self::US_IP]], $rows->map(fn ($r) => [$r->country_code, $r->timezone, $r->ip_address])->all());
    }

    public function test_security_events_and_fraud_checks_carry_the_same_trace(): void
    {
        $this->makeUser('who@example.com', 'admin');
        $this->fromIp(self::US_IP)->adminGql('mutation($i: AdminLoginInput!) { adminLogin(input: $i) { token } }', ['i' => ['email' => 'who@example.com', 'password' => self::PASSWORD]])->assertJsonMissingPath('errors');

        $event = DB::table('security_events')->orderByDesc('id')->first();
        $this->assertSame(['US', 'America/Los_Angeles'], [$event->country_code, $event->timezone]);

        app(FraudService::class)->assess(new FraudContext(self::MX_IP, 'a@b.mx', null, new Money('10', 'MXN'), 'MX', 'MX'), 'o-1');
        $check = DB::table('fraud_checks')->where('order_id', 'o-1')->first();
        $this->assertSame(['US', 'America/Los_Angeles'], [$check->country_code, $check->timezone], 'the check runs inside the request of the last caller');
    }

    public function test_a_locator_that_is_swapped_in_drives_everything(): void
    {
        $this->app->bind(GeoLocator::class, fn () => new class implements GeoLocator
        {
            public function locate(?string $ip, ?string $countryHint = null): GeoLocation
            {
                return new GeoLocation($ip, 'CO', 'America/Bogota', 'ranges');
            }
        });
        app()->forgetInstance(CurrentClient::class);

        $this->assertSame('America/Bogota', $this->gql('{ clientContext { timezone } }')->json('data.clientContext.timezone'));
    }

    public function test_the_audit_trail_can_be_browsed_by_country_with_local_and_utc_times(): void
    {
        $token = $this->adminToken();
        $this->fromIp(self::MX_IP)->adminGql('mutation { createTaxClass(name: "Reducida") { id } }', [], $token)->assertJsonMissingPath('errors');
        $this->fromIp(self::US_IP)->adminGql('mutation { createTaxClass(name: "Exenta") { id } }', [], $token)->assertJsonMissingPath('errors');

        $q = 'query($c: String) { adminAudits(entity: "TaxClassModel", country: $c, first: 1) { nextBefore entries { event entity country timezone ip createdAt createdAtUtc userEmail newValues } } }';
        $us = $this->fromIp(self::MX_IP)->adminGql($q, ['c' => 'us'], $token)->assertJsonMissingPath('errors')->json('data.adminAudits');

        $this->assertNull($us['nextBefore']);
        $entry = $us['entries'][0];
        $this->assertSame(['created', 'TaxClassModel', 'US', 'America/Los_Angeles', self::US_IP, 'boss@example.com'], [$entry['event'], $entry['entity'], $entry['country'], $entry['timezone'], $entry['ip'], $entry['userEmail']]);
        $this->assertSame('Exenta', $entry['newValues']['name']);
        $this->assertStringEndsWith('Z', $entry['createdAtUtc']);
        $this->assertStringEndsWith('-06:00', $entry['createdAt'], 'shown on the viewer\'s (Mexico City) clock');
        $this->assertSame(Carbon::parse($entry['createdAtUtc'])->getTimestamp(), Carbon::parse($entry['createdAt'])->getTimestamp(), 'both are the same instant');

        $all = $this->adminGql('{ adminAudits(entity: "TaxClassModel", first: 1) { nextBefore entries { id } } }', [], $token)->json('data.adminAudits');
        $this->assertNotNull($all['nextBefore'], 'more entries remain');
    }

    public function test_only_roles_with_audits_view_can_read_the_trail(): void
    {
        $support = $this->adminToken('help@example.com', 'support');

        $this->adminGql('{ adminAudits { nextBefore } }', [], $support)->assertJsonPath('errors.0.message', 'This action is unauthorized.');
    }
}
