<?php

return [
    'order_placed' => [
        'subject' => 'Recibimos tu pedido :number',
        'title' => '¡Gracias por tu compra!',
        'intro' => 'Confirmamos tu pago de :total :currency. Tu pedido :number ya está en preparación.',
        'button' => 'Seguir mi pedido',
        'items' => 'Resumen',
    ],
    'order_status' => [
        'subject' => 'Tu pedido :number: :status_label',
        'title' => 'Actualización de tu pedido :number',
        'delivered' => 'Tu pedido fue entregado. ¡Esperamos que lo disfrutes!',
        'cancelled' => 'Tu pedido fue cancelado. Si ya habías pagado, el reembolso se hace al mismo medio de pago.',
        'refunded' => 'Reembolsamos tu pedido. Puede tardar unos días en reflejarse en tu estado de cuenta.',
        'button' => 'Ver mi pedido',
        'labels' => ['delivered' => 'entregado', 'cancelled' => 'cancelado', 'refunded' => 'reembolsado'],
    ],
    'shipment_created' => [
        'subject' => 'Tu pedido :number va en camino',
        'title' => '¡Tu pedido va en camino!',
        'intro' => 'Lo enviamos con :carrier.',
        'tracking' => 'Número de guía: :tracking_number',
        'partial' => 'Este envío contiene una parte de tu pedido; el resto saldrá por separado.',
        'button' => 'Rastrear mi pedido',
    ],
    'quote_ready' => [
        'subject' => 'Tu cotización :number está lista',
        'title' => 'Tu cotización está lista',
        'intro' => 'Preparamos una cotización por :total :currency, válida hasta :valid_until.',
        'button' => 'Ver cotización',
    ],
    'custom_order_update' => [
        'subject' => 'Novedades de tu pedido personalizado :number',
        'title' => 'Novedades de tu pedido personalizado',
        'intro' => ':message',
        'button' => 'Ver mi solicitud',
    ],
    'ops_alert' => [
        'subject' => ':subject_line',
        'title' => 'Requiere revisión',
        'intro' => ':detail',
    ],
];
