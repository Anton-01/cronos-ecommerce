<?php

return [
    // GraphQL is the only public surface (webhooks are server-to-server and need no CORS).
    'paths' => ['graphql', 'admin/graphql'],

    'allowed_methods' => ['POST', 'OPTIONS'],

    // Comma separated list of exact origins, e.g. the admin SPA and the storefront.
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        env('FRONTEND_URL', 'http://localhost:5173').','.env('STOREFRONT_URL', 'http://localhost:3000'),
    ))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Correlation-Id', 'Idempotency-Key', 'Apollo-Require-Preflight'],

    'exposed_headers' => ['X-Correlation-Id'],

    'max_age' => 600,

    // Bearer tokens, not cookies.
    'supports_credentials' => false,
];
