<?php

declare(strict_types=1);

namespace Cronos\Appearance\Domain\Services;

use Cronos\Appearance\Domain\Exceptions\ThemeError;

/** Switches for what the storefront shows. Some modules do not exist yet: their switch is visible, off and locked, ready for later. */
final class FeatureFlags
{
    /** @var array<string, array{label: string, default: bool}> */
    public const AVAILABLE = [
        'wishlist' => ['label' => 'Favoritos', 'default' => true],
        'customOrders' => ['label' => 'Pedidos personalizados', 'default' => true],
        'searchSuggestions' => ['label' => 'Sugerencias al buscar', 'default' => true],
        'storeSelector' => ['label' => 'Selector de tienda / país', 'default' => true],
        'guestCheckout' => ['label' => 'Comprar sin cuenta', 'default' => true],
        'orderTracking' => ['label' => 'Rastreo de pedidos', 'default' => true],
        'newsletter' => ['label' => 'Boletín (suscripción en el pie de página)', 'default' => true],
    ];

    /** @var array<string, string> */
    public const RESERVED = [
        'reviews' => 'Valoraciones de clientes',
        'compare' => 'Comparar productos',
        'brands' => 'Marcas',
        'blog' => 'Blog',
        'giftCards' => 'Tarjetas de regalo',
    ];

    /** @return array<string, bool> */
    public static function defaults(): array
    {
        return array_map(static fn (array $f) => $f['default'], self::AVAILABLE) + array_fill_keys(array_keys(self::RESERVED), false);
    }

    /** @param array<string, mixed> $in @return array<string, bool> */
    public static function normalize(array $in): array
    {
        $out = self::defaults();
        $errors = [];
        foreach ($in as $key => $value) {
            if (! is_bool($value)) {
                $errors["features.{$key}"] = 'Debe ser sí o no.';
            } elseif (isset(self::RESERVED[$key])) {
                $value === false || $errors["features.{$key}"] = self::RESERVED[$key].' todavía no está disponible.';
            } elseif (isset(self::AVAILABLE[$key])) {
                $out[$key] = $value;
            } else {
                $errors["features.{$key}"] = 'Opción desconocida.';
            }
        }
        $errors === [] || throw new ThemeError('Hay opciones que no se pueden usar.', 'INVALID_THEME', $errors);

        return $out;
    }
}
