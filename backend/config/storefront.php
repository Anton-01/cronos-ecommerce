<?php

declare(strict_types=1);

return [
    'url' => env('STOREFRONT_URL', 'http://localhost:3000'),

    // Where the storefront listens for "this changed" notices, and the shared secret that proves they come from us. Leave the secret empty
    // to turn the notices off (the storefront then relies on its own cache time limits only).
    'revalidate_url' => env('STOREFRONT_REVALIDATE_URL', rtrim((string) env('STOREFRONT_URL', 'http://localhost:3000'), '/').'/api/revalidate'),
    'revalidate_secret' => env('STOREFRONT_REVALIDATE_SECRET', ''),
];
