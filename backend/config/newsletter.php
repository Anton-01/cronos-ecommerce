<?php

declare(strict_types=1);

return [
    'min_fill_seconds' => (int) env('NEWSLETTER_MIN_FILL_SECONDS', 2),
    'max_per_ip_per_hour' => (int) env('NEWSLETTER_MAX_PER_IP_HOUR', 10),
    'max_per_email_per_day' => (int) env('NEWSLETTER_MAX_PER_EMAIL_DAY', 3),
];
