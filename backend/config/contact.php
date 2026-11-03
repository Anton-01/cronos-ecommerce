<?php

declare(strict_types=1);

return [
    // A person typing a message needs more than this many seconds; a script posting the form straight away does not.
    'min_fill_seconds' => (int) env('CONTACT_MIN_FILL_SECONDS', 3),
    'max_per_ip_per_hour' => (int) env('CONTACT_MAX_PER_IP_HOUR', 5),
    'max_per_email_per_day' => (int) env('CONTACT_MAX_PER_EMAIL_DAY', 3),
    'max_links' => 2,

    'topics' => ['general', 'order', 'custom', 'wholesale', 'other'],
];
