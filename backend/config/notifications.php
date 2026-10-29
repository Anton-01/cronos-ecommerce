<?php

declare(strict_types=1);

return [
    // Where payment anomalies (amount mismatch, late money, disputes) are e-mailed. Empty = only the critical log.
    'ops_email' => env('OPS_ALERT_EMAIL'),
];
