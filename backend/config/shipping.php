<?php

declare(strict_types=1);

return [
    // Carriers allowed to call POST /webhooks/shipping/{carrier}. A carrier without a secret cannot send anything.
    'carriers' => [
        'estafeta' => ['webhook_secret' => env('SHIPPING_ESTAFETA_SECRET')],
        'dhl' => ['webhook_secret' => env('SHIPPING_DHL_SECRET')],
        'fedex' => ['webhook_secret' => env('SHIPPING_FEDEX_SECRET')],
        'ups' => ['webhook_secret' => env('SHIPPING_UPS_SECRET')],
        'usps' => ['webhook_secret' => env('SHIPPING_USPS_SECRET')],
    ],
];
