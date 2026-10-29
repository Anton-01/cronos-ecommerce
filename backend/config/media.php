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

    'conversion_format' => 'webp',
    'conversion_quality' => 82,

    // Collections that keep one file only: attaching a new one replaces the previous.
    'single_file_collections' => ['avatar'],
];
