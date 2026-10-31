<?php

namespace Tests\Feature\Geo;

use Database\Seeders\PricingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\GraphQLTestCase;

final class GeoIpFamiliesTest extends GraphQLTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PricingSeeder::class);
        Cache::flush();
        $csv = tempnam(sys_get_temp_dir(), 'geo');
        file_put_contents($csv, implode("\n", [
            '187.190.0.0/15,MX,America/Mexico_City',
            '8.8.0.0,8.8.255.255,US,America/Los_Angeles',
            '2001:4860::/32,US,America/Chicago',
            '2806:230::/32,MX,',
            '1.2.3.4,2001:db8::1,US,',      // mixed families
            '9.9.9.9,9.9.9.1,US,',          // reversed
            '10.0.0.0/33,US,',              // bad prefix
        ]));
        $this->artisan('geo:import', ['file' => $csv])->assertSuccessful();
        unlink($csv);
    }

    private function client(string $ip): array
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->gql('{ clientContext { ip country timezone source suggestedMarket } }')->assertJsonMissingPath('errors')->json('data.clientContext');
    }

    public function test_cidr_blocks_in_both_families_load_and_broken_lines_are_skipped_without_aborting(): void
    {
        $this->assertSame(4, DB::table('geo_ip_ranges')->count());
        $this->assertSame('187.191.255.255', DB::table('geo_ip_ranges')->where('country_code', 'MX')->orderBy('id')->value('ip_to'));
    }

    public function test_ipv6_visitors_get_their_country_clock_and_market_like_ipv4_ones(): void
    {
        $us = $this->client('2001:4860:4860::8888');
        $this->assertSame(['US', 'America/Chicago', 'ranges', 'US'], [$us['country'], $us['timezone'], $us['source'], $us['suggestedMarket']]);

        $mx = $this->client('2806:230:1234::1');
        $this->assertSame(['MX', 'America/Mexico_City', 'MX'], [$mx['country'], $mx['timezone'], $mx['suggestedMarket']]);
    }

    public function test_an_ipv4_client_on_an_ipv6_socket_is_the_same_visitor(): void
    {
        $c = $this->client('::ffff:187.190.10.20');
        $this->assertSame(['187.190.10.20', 'MX', 'ranges'], [$c['ip'], $c['country'], $c['source']]);
    }

    public function test_private_loopback_and_unknown_ipv6_addresses_fall_back_instead_of_guessing(): void
    {
        foreach (['::1', 'fd12:3456:789a::1', 'fe80::1', '2a00:1450:4001::1'] as $ip) {
            $c = $this->client($ip);
            $this->assertSame([null, 'fallback', null], [$c['country'], $c['source'], $c['suggestedMarket']], $ip);
        }
    }

    public function test_an_ipv4_address_never_matches_an_ipv6_range_or_the_other_way_round(): void
    {
        // 0.0.0.1 sorts below every range; ::1 would sort above every IPv4 range in a mixed-family index.
        $this->assertNull($this->client('1.1.1.1')['country']);
        $this->assertNull($this->client('2001:db8::1')['country']);
    }
}
