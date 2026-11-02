<?php

declare(strict_types=1);

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
        // The widget is created with this action and the verdict must carry it back: a token solved on another form is refused.
        'action' => 'contact',
        // Optional: the hostname Cloudflare reports the token was solved on (your storefront's host). Empty = not checked.
        'hostname' => env('TURNSTILE_EXPECTED_HOSTNAME', ''),
    ],

    // A person typing a message needs more than this many seconds; a script posting the form straight away does not.
    'min_fill_seconds' => (int) env('CONTACT_MIN_FILL_SECONDS', 3),
    'max_per_ip_per_hour' => (int) env('CONTACT_MAX_PER_IP_HOUR', 5),
    'max_per_email_per_day' => (int) env('CONTACT_MAX_PER_EMAIL_DAY', 3),
    'max_links' => 2,

    'topics' => ['general', 'order', 'custom', 'wholesale', 'other'],
];
