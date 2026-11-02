<?php

declare(strict_types=1);

/**
 * What every kind of picture in the shop must look like to always look good. One place, used by the upload (hard minimum, soft
 * warnings), by the pipeline (which widths to build) and by the admin (guidance next to each upload box).
 *
 *  collection  where the file is filed
 *  ratio       width / height the design expects (null = any); the soft warning fires beyond `tolerance`
 *  recommended the size to export from your design tool (px); bigger is wasted, smaller may look soft
 *  min         hard minimum (px): below it the upload is refused
 *  widths      widths of the optimised renditions the storefront chooses from (never larger than the original)
 *  focal       the picture is cropped by the layout, so the admin can mark where the subject is
 *  svg         a sanitised SVG is accepted (logos only)
 */
return [
    'slots' => [
        'product' => ['label' => 'Producto', 'collection' => 'gallery', 'ratio' => 1.0, 'recommended' => [2000, 2000], 'min' => [800, 800], 'widths' => [400, 800, 1200, 1600, 2000], 'focal' => false, 'svg' => false,
            'hint' => 'Cuadrada, producto centrado con margen; fondo blanco o transparente.'],
        'category' => ['label' => 'Categoría (círculo)', 'collection' => 'category', 'ratio' => 1.0, 'recommended' => [1200, 1200], 'min' => [400, 400], 'widths' => [300, 600, 900, 1200], 'focal' => true, 'svg' => false,
            'hint' => 'Cuadrada; se recorta en círculo, deja el tema en el centro.'],
        'hero' => ['label' => 'Portada (escritorio)', 'collection' => 'hero', 'ratio' => 2.4, 'recommended' => [2560, 1067], 'min' => [1280, 533], 'widths' => [640, 1024, 1440, 1920, 2560], 'focal' => true, 'svg' => false,
            'hint' => 'Muy ancha (12:5). El texto va a la izquierda; deja esa zona limpia.'],
        'hero_mobile' => ['label' => 'Portada (móvil)', 'collection' => 'hero', 'ratio' => 0.8, 'recommended' => [1200, 1500], 'min' => [600, 750], 'widths' => [480, 800, 1200], 'focal' => true, 'svg' => false,
            'hint' => 'Vertical (4:5) para pantallas de teléfono.'],
        'banner' => ['label' => 'Banner de título', 'collection' => 'banner', 'ratio' => 4.8, 'recommended' => [2560, 533], 'min' => [1280, 267], 'widths' => [640, 1024, 1440, 1920, 2560], 'focal' => true, 'svg' => false,
            'hint' => 'Franja muy ancha (24:5) detrás de títulos; se oscurece levemente para leer el texto.'],
        'content' => ['label' => 'Contenido / colección', 'collection' => 'content', 'ratio' => null, 'recommended' => [1600, 1200], 'min' => [600, 400], 'widths' => [480, 800, 1200, 1600], 'focal' => true, 'svg' => false,
            'hint' => 'Cualquier proporción; se usa en mosaicos y bloques editoriales.'],
        'logo' => ['label' => 'Logotipo', 'collection' => 'branding', 'ratio' => null, 'recommended' => [1200, 320], 'min' => [160, 48], 'widths' => [240, 480, 960], 'focal' => false, 'svg' => true,
            'hint' => 'PNG transparente o SVG. Se muestra a 40–64 px de alto: evita textos finos. Cualquier proporción hasta 6:1.'],
        'favicon' => ['label' => 'Favicon / ícono de app', 'collection' => 'branding', 'ratio' => 1.0, 'recommended' => [512, 512], 'min' => [256, 256], 'widths' => [], 'focal' => false, 'svg' => false,
            'hint' => 'Cuadrada de 512×512, formas simples y con margen del 10 %. De aquí salen 16, 32, 48, 180, 192 y 512 px y el .ico.'],
        'og' => ['label' => 'Imagen para compartir (redes)', 'collection' => 'branding', 'ratio' => 1.91, 'recommended' => [1200, 630], 'min' => [600, 315], 'widths' => [600, 1200], 'focal' => true, 'svg' => false,
            'hint' => '1200×630; es lo que se ve al compartir un enlace de la tienda.'],
        'library' => ['label' => 'Biblioteca', 'collection' => 'library', 'ratio' => null, 'recommended' => [1, 1], 'min' => [1, 1], 'widths' => [320, 640, 960, 1280, 1920], 'focal' => true, 'svg' => false,
            'hint' => 'Sin requisitos.'],
    ],

    'ratio_tolerance' => 0.08,

    // Largest rendition ever generated, whatever the original: a 6000 px photo is never worth serving at 6000 px.
    'max_width' => 2560,

    // Lower quality where it cannot be seen: small renditions hide artefacts, big ones need more care. [max width => quality]
    'quality_by_width' => [640 => 76, 1280 => 80, 99999 => 82],

    // Next-generation format next to WebP when the server can encode it (auto = only if GD/Imagick support it).
    'avif' => env('MEDIA_AVIF', 'auto'),
    'avif_quality' => 55,
];
