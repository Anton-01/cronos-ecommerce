<?php

declare(strict_types=1);

return [
    'min_fill_seconds' => (int) env('NEWSLETTER_MIN_FILL_SECONDS', 2),
    'max_per_ip_per_hour' => (int) env('NEWSLETTER_MAX_PER_IP_HOUR', 10),
    'max_per_email_per_day' => (int) env('NEWSLETTER_MAX_PER_EMAIL_DAY', 3),

    // Campaigns are sent through Resend (https://resend.com): API key from the dashboard → API Keys; the sending domain must be verified there.
    'resend' => [
        'api_key' => env('RESEND_API_KEY'),
        'base_url' => env('RESEND_BASE_URL', 'https://api.resend.com'),
        // "Shop name <news@your-domain.com>": the address must belong to a domain verified in Resend.
        'from' => env('NEWSLETTER_FROM'),
        'reply_to' => env('NEWSLETTER_REPLY_TO'),
        // Webhooks (Resend → Webhooks → https://API/webhooks/resend, events email.delivered / email.bounced / email.complained): the signing secret "whsec_…".
        'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
        'timeout' => (int) env('RESEND_TIMEOUT', 20),
    ],

    // Resend's batch endpoint takes up to 100 messages and its default limit is 2 requests per second: one batch per second keeps well inside it.
    'batch_size' => 100,
    'seconds_between_batches' => 1,
    // Shown at the bottom of every campaign (legal requirement in most countries). The store's fiscal address is used when it has one.
    'postal_fallback' => env('NEWSLETTER_POSTAL_ADDRESS', ''),
];
