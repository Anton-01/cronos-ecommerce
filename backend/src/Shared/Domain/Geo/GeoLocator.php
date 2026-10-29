<?php

declare(strict_types=1);

namespace Cronos\Shared\Domain\Geo;

interface GeoLocator
{
    /** Never throws: an unknown, private or malformed address resolves to the configured fallback. */
    public function locate(?string $ip, ?string $countryHint = null): GeoLocation;
}
