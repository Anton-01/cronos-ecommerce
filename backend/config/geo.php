<?php

return [
    // Clock used when the caller's location is unknown (local development, private networks, IPs missing from the ranges table).
    'default_timezone' => env('GEO_DEFAULT_TIMEZONE', 'America/Mexico_City'),

    // Timezone of a country when the ranges table has no timezone for the IP. Countries that span several zones (the US) only
    // get a sensible default here; import ranges with a timezone column (geo:import) for per-region accuracy.
    'country_timezones' => [
        'MX' => 'America/Mexico_City',
        'US' => 'America/New_York',
        'CA' => 'America/Toronto',
        'GT' => 'America/Guatemala',
        'CO' => 'America/Bogota',
        'ES' => 'Europe/Madrid',
    ],

    // Header set by a trusted CDN/load balancer with the visitor's country (CF-IPCountry, CloudFront-Viewer-Country...).
    // Only believed when the request arrives through a trusted proxy (TRUSTED_PROXIES).
    'country_header' => env('GEO_COUNTRY_HEADER'),

    // Lookups are cached per IP for this many seconds.
    'cache_ttl' => (int) env('GEO_CACHE_TTL', 3600),
];
