<?php

return [
    // Seconds to wait for a provider before treating the call as "not answering" (safe to retry: every call carries an idempotency key).
    'timeout' => (int) env('PAYMENTS_TIMEOUT', 15),
    'connect_timeout' => (int) env('PAYMENTS_CONNECT_TIMEOUT', 5),

    // Public base URL providers call back (must be reachable from the internet: use a tunnel in development).
    'webhook_base_url' => env('PAYMENTS_WEBHOOK_BASE_URL'),

    // How long a customer has to pay after starting checkout; the stock reservation lasts as long.
    'checkout_ttl_minutes' => (int) env('PAYMENTS_CHECKOUT_TTL', 30),
];
