<?php

declare(strict_types=1);

// "Is this a person?" for every public form (contact, newsletter). Each form asks for its own `action`; a token solved on another form is refused.
return [
    // Cloudflare Turnstile (the "is this a person?" check). Both keys come from the Cloudflare dashboard → Turnstile → the widget of this shop.
    // The SITE key is public (the browser needs it); the SECRET stays on the server. Leave the secret empty to run without the check (local development).
    // Cloudflare's own test keys: site 1x00000000000000000000AA / secret 1x0000000000000000000000000000000AA always pass;
    // site 2x00000000000000000000AB / secret 2x0000000000000000000000000000000AA always fail.
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret' => env('TURNSTILE_SECRET'),
        'verify_url' => env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
        'timeout' => (int) env('TURNSTILE_TIMEOUT', 5),
        // Optional: the hostname Cloudflare reports the token was solved on (your storefront's host). Empty = not checked.
        'hostname' => env('TURNSTILE_EXPECTED_HOSTNAME', ''),
    ],
];
