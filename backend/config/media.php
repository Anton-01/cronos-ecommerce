<?php

return [
    'disk' => env('MEDIA_DISK', 'media'),

    'max_upload_kb' => (int) env('MEDIA_MAX_UPLOAD_KB', 10240),

    // Largest accepted image, checked from the header BEFORE decoding (decompression-bomb guard).
    'max_pixels' => (int) env('MEDIA_MAX_PIXELS', 24_000_000),

    // Real (sniffed) MIME type => stored extension. SVG is deliberately absent: it can carry scripts.
    'allowed_mimes' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
    ],

    // Derived images generated in the background. fit=cover crops to the exact box, contain keeps the ratio.
    'conversions' => [
        'thumb' => ['width' => 160, 'height' => 160, 'fit' => 'cover'],
        'medium' => ['width' => 640, 'height' => 640, 'fit' => 'contain'],
        'large' => ['width' => 1600, 'height' => 1600, 'fit' => 'contain'],
    ],

    // Width-only renditions for srcset (height follows the image's ratio). Widths wider than the original are skipped.
    'responsive_widths' => [320, 640, 960, 1280, 1920],

    // Soft-deleted items keep their files this many days (so a mistaken delete can be undone) before media:purge removes them.
    'purge_after_days' => (int) env('MEDIA_PURGE_AFTER_DAYS', 30),

    // Malware scan of every upload. `clamav` talks to a clamd daemon (host/port or unix socket); with fail_closed an
    // unreachable scanner refuses uploads instead of letting them through unscanned.
    'antivirus' => [
        'driver' => env('MEDIA_ANTIVIRUS', 'null'),
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'socket' => env('CLAMAV_SOCKET'),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 10),
        'fail_closed' => (bool) env('MEDIA_ANTIVIRUS_FAIL_CLOSED', true),
    ],

    'conversion_format' => 'webp',
    'conversion_quality' => 82,

    // Collections that keep one file only: attaching a new one replaces the previous.
    'single_file_collections' => ['avatar'],
];
