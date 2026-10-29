<?php

declare(strict_types=1);

return [
    'cart_ttl_days' => (int) env('CART_TTL_DAYS', 30),

    // Minutes a shopper has to pay once checkout starts: the stock reservation lasts as long.
    'ttl_minutes' => (int) env('CHECKOUT_TTL', 30),

    // After a checkout times out, wait this long for money that may still be in flight before giving the stock back.
    'expiry_grace_minutes' => (int) env('CHECKOUT_EXPIRY_GRACE', 60),

    // Flat-rate shipping per market (market currency): free above the threshold. Replace with a carrier rater in Phase 8.
    'shipping' => [
        'MX' => ['flat' => '150.00', 'free_over' => '1500.00'],
        'US' => ['flat' => '12.00', 'free_over' => '100.00'],
    ],
];
