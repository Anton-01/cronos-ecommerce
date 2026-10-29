<?php

declare(strict_types=1);

namespace Cronos\Shared\Infrastructure\Geo;

use Cronos\Shared\Domain\Geo\GeoLocation;
use Cronos\Shared\Domain\Geo\GeoLocator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Resolves an IP against the geo_ip_ranges table (nearest range start at or below the IP, then checked against its end).
 * Precedence for the timezone: the range's own, then the country's default, then the configured fallback.
 */
final class DatabaseGeoLocator implements GeoLocator
{
    public function locate(?string $ip, ?string $countryHint = null): GeoLocation
    {
        $fallback = (string) config('geo.default_timezone');
        $hint = $this->country($countryHint);

        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $this->fromCountry($ip, $hint, GeoLocation::SOURCE_HEADER, $fallback);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return new GeoLocation($ip, null, $this->valid($fallback) ?? 'UTC', GeoLocation::SOURCE_FALLBACK);
        }

        $row = Cache::remember('geo:'.Cache::get('geo:generation', 0).':'.$ip, (int) config('geo.cache_ttl'), function () use ($ip): array|false {
            $found = DB::selectOne('SELECT ip_to >= ?::inet AS hit, country_code, timezone FROM geo_ip_ranges WHERE ip_from <= ?::inet ORDER BY ip_from DESC LIMIT 1', [$ip, $ip]);

            return $found !== null && $found->hit ? ['country' => $found->country_code, 'timezone' => $found->timezone] : false;
        });

        if ($row !== false) {
            $tz = $this->valid($row['timezone']) ?? $this->valid(config('geo.country_timezones')[$row['country']] ?? null) ?? $this->valid($fallback) ?? 'UTC';

            return new GeoLocation($ip, $row['country'], $tz, GeoLocation::SOURCE_RANGES);
        }

        return $this->fromCountry($ip, $hint, GeoLocation::SOURCE_HEADER, $fallback);
    }

    private function fromCountry(?string $ip, ?string $country, string $source, string $fallback): GeoLocation
    {
        if ($country === null) {
            return new GeoLocation($ip, null, $this->valid($fallback) ?? 'UTC', GeoLocation::SOURCE_FALLBACK);
        }

        return new GeoLocation($ip, $country, $this->valid(config('geo.country_timezones')[$country] ?? null) ?? $this->valid($fallback) ?? 'UTC', $source);
    }

    private function country(?string $code): ?string
    {
        $code = $code === null ? null : strtoupper(trim($code));

        return $code !== null && preg_match('/^[A-Z]{2}$/', $code) === 1 && $code !== 'XX' && $code !== 'T1' ? $code : null;
    }

    private function valid(?string $timezone): ?string
    {
        return $timezone !== null && in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : null;
    }
}
