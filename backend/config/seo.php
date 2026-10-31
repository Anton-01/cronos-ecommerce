<?php

declare(strict_types=1);

return [
    // Public address of the storefront (canonical URLs, sitemap, Open Graph). Defaults to STOREFRONT_URL.
    'base_url' => env('SEO_BASE_URL'),

    'site_name' => env('SEO_SITE_NAME', 'Cronos'),

    'default_locale' => 'es-MX',

    // The URL scheme of the storefront, one source of truth: the shop reads it (seoRoutes) and the API builds canonicals, hreflang and the
    // sitemap from it. /{prefix}  /{prefix}/{product}/{slug}  /{prefix}/{category}/{slug}
    'locales' => [
        'es-MX' => ['prefix' => 'es-mx', 'product' => 'productos', 'category' => 'categorias', 'og' => 'es_MX'],
        'en-US' => ['prefix' => 'en-us', 'product' => 'products', 'category' => 'categories', 'og' => 'en_US'],
    ],

    // Pages that must never appear in a search engine (private, transactional or personal). Under each locale prefix.
    'noindex_paths' => ['cart', 'checkout', 'account', 'orders', 'login', 'register', 'wishlist', 'search'],

    'sitemap_page_size' => (int) env('SEO_SITEMAP_PAGE_SIZE', 5000),

    'sitemap_cache_seconds' => (int) env('SEO_SITEMAP_CACHE', 900),

    // Search engines are kept out of everything unless this is the real production site (a staging copy must never get indexed).
    'allow_indexing' => (bool) env('SEO_ALLOW_INDEXING', env('APP_ENV') === 'production'),
];
