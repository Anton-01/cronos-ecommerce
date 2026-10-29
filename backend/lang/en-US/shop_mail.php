<?php

return [
    'order_placed' => [
        'subject' => 'We received your order :number',
        'title' => 'Thank you for your purchase!',
        'intro' => 'We confirmed your payment of :total :currency. Order :number is now being prepared.',
        'button' => 'Track my order',
        'items' => 'Summary',
    ],
    'order_status' => [
        'subject' => 'Your order :number: :status_label',
        'title' => 'Update on your order :number',
        'delivered' => 'Your order was delivered. We hope you enjoy it!',
        'cancelled' => 'Your order was cancelled. If you had already paid, the refund goes back to the same payment method.',
        'refunded' => 'We refunded your order. It can take a few days to show on your statement.',
        'button' => 'View my order',
        'labels' => ['delivered' => 'delivered', 'cancelled' => 'cancelled', 'refunded' => 'refunded'],
    ],
    'shipment_created' => [
        'subject' => 'Your order :number is on its way',
        'title' => 'Your order is on its way!',
        'intro' => 'We shipped it with :carrier.',
        'tracking' => 'Tracking number: :tracking_number',
        'partial' => 'This shipment holds part of your order; the rest will ship separately.',
        'button' => 'Track my order',
    ],
    'quote_ready' => [
        'subject' => 'Your quote :number is ready',
        'title' => 'Your quote is ready',
        'intro' => 'We prepared a quote for :total :currency, valid until :valid_until.',
        'button' => 'View quote',
    ],
    'custom_order_update' => [
        'subject' => 'News about your custom order :number',
        'title' => 'News about your custom order',
        'intro' => ':message',
        'button' => 'View my request',
    ],
    'ops_alert' => [
        'subject' => ':subject_line',
        'title' => 'Needs review',
        'intro' => ':detail',
    ],
];
