<?php

declare(strict_types=1);

namespace Cronos\Appearance\Domain\Services;

use Cronos\Appearance\Domain\Exceptions\ThemeError;

/**
 * The home page as an ordered list of sections the admin can add, reorder, switch off and edit. Each type has its own few, checked settings.
 * Types that need a module that does not exist yet (brands, collections, blog) are accepted but kept switched off: the day the module
 * arrives the saved setup is already there to resume.
 */
final class HomeSchema
{
    public const TYPES = ['hero', 'categories', 'products', 'banner', 'text'];

    /** Known to the design, not buildable yet: stored, never shown. */
    public const RESERVED = ['brands', 'collections', 'blog'];

    private const MAX_SECTIONS = 20;

    /** @return list<array<string, mixed>> */
    public static function defaults(): array
    {
        return [
            ['id' => 'hero', 'type' => 'hero', 'enabled' => true, 'settings' => ['autoplay' => 6, 'slides' => []]],
            ['id' => 'categories', 'type' => 'categories', 'enabled' => true, 'settings' => ['title' => ['es-MX' => 'Nuestras categorías', 'en-US' => 'Our categories'], 'subtitle' => ['es-MX' => '', 'en-US' => ''], 'mode' => 'auto', 'categoryIds' => [], 'max' => 10]],
            ['id' => 'new-products', 'type' => 'products', 'enabled' => true, 'settings' => ['title' => ['es-MX' => 'Novedades', 'en-US' => 'New arrivals'], 'source' => 'newest', 'categoryId' => null, 'productIds' => [], 'limit' => 10]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  list<string>  $locales
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $sections, array $locales): array
    {
        $errors = [];
        count($sections) <= self::MAX_SECTIONS || throw new ThemeError('La portada admite hasta '.self::MAX_SECTIONS.' secciones.', 'INVALID_THEME', ['home' => 'Demasiadas secciones.']);
        $seen = [];
        $out = [];

        foreach (array_values($sections) as $i => $s) {
            $p = "home.{$i}";
            $id = (string) ($s['id'] ?? '');
            preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $id) === 1 || $errors["{$p}.id"] = 'Identificador: minúsculas, números y guiones (máx. 40).';
            isset($seen[$id]) && $errors["{$p}.id"] = 'Identificador repetido.';
            $seen[$id] = true;
            $type = (string) ($s['type'] ?? '');
            $enabled = (bool) ($s['enabled'] ?? true);
            $settings = (array) ($s['settings'] ?? []);

            if (in_array($type, self::RESERVED, true)) {
                strlen((string) json_encode($settings)) <= 20000 || $errors["{$p}.settings"] = 'Demasiado grande.';
                $out[] = ['id' => $id, 'type' => $type, 'enabled' => false, 'reserved' => true, 'settings' => $settings];

                continue;
            }
            if (! in_array($type, self::TYPES, true)) {
                $errors["{$p}.type"] = 'Tipo de sección desconocido.';

                continue;
            }
            $out[] = ['id' => $id, 'type' => $type, 'enabled' => $enabled, 'settings' => self::settings($type, $settings, $locales, $p, $errors)];
        }

        $errors === [] || throw new ThemeError('La portada tiene valores que no se pueden usar.', 'INVALID_THEME', $errors);

        return $out;
    }

    /** @param array<string, mixed> $s @param list<string> $locales @param array<string, string> $errors @return array<string, mixed> */
    private static function settings(string $type, array $s, array $locales, string $p, array &$errors): array
    {
        $text = fn (string $key, int $max, bool $required = false) => self::optionalText($s[$key] ?? [], $locales, $max, "{$p}.settings.{$key}", $required, $errors);
        $url = function (string $key) use ($s, $p, &$errors): ?string {
            $v = $s[$key] ?? null;
            if ($v === null || $v === '') {
                return null;
            }
            NavigationSchema::linkOk((string) $v) || $errors["{$p}.settings.{$key}"] = 'Enlace no válido.';

            return (string) $v;
        };
        $media = function (mixed $v, string $key) use ($p, &$errors): ?string {
            if ($v === null || $v === '') {
                return null;
            }
            preg_match('/^[0-9a-z]{26}$/', (string) $v) === 1 || $errors["{$p}.settings.{$key}"] = 'Imagen no válida.';

            return (string) $v;
        };
        $enum = function (string $key, array $allowed, string $default) use ($s, $p, &$errors): string {
            $v = $s[$key] ?? $default;
            in_array($v, $allowed, true) || $errors["{$p}.settings.{$key}"] = 'Opción no válida: '.implode(', ', $allowed).'.';

            return (string) $v;
        };
        $int = function (string $key, int $min, int $max, int $default) use ($s, $p, &$errors): int {
            $v = $s[$key] ?? $default;
            (is_numeric($v) && $v >= $min && $v <= $max) || $errors["{$p}.settings.{$key}"] = "Debe estar entre {$min} y {$max}.";

            return (int) $v;
        };
        $ids = function (string $key, int $max, string $pattern) use ($s, $p, &$errors): array {
            $list = array_values(array_unique(array_map('strval', (array) ($s[$key] ?? []))));
            count($list) <= $max || $errors["{$p}.settings.{$key}"] = "Máximo {$max}.";
            foreach ($list as $id) {
                preg_match($pattern, $id) === 1 || $errors["{$p}.settings.{$key}"] = 'Identificador no válido.';
            }

            return $list;
        };

        switch ($type) {
            case 'hero':
                $slides = [];
                foreach (array_values((array) ($s['slides'] ?? [])) as $j => $sl) {
                    if ($j >= 6) {
                        $errors["{$p}.settings.slides"] = 'Máximo 6 imágenes en la portada.';
                        break;
                    }
                    $q = "{$p}.settings.slides.{$j}";
                    $ctaUrl = $sl['ctaUrl'] ?? null;
                    ($ctaUrl === null || $ctaUrl === '' || NavigationSchema::linkOk((string) $ctaUrl)) || $errors["{$q}.ctaUrl"] = 'Enlace no válido.';
                    $overlay = $sl['overlay'] ?? 0;
                    (is_numeric($overlay) && $overlay >= 0 && $overlay <= 70) || $errors["{$q}.overlay"] = 'Entre 0 y 70.';
                    in_array($sl['align'] ?? 'left', ['left', 'center', 'right'], true) || $errors["{$q}.align"] = 'left, center o right.';
                    $slides[] = [
                        'image' => $media($sl['image'] ?? null, "slides.{$j}.image"), 'imageMobile' => $media($sl['imageMobile'] ?? null, "slides.{$j}.imageMobile"),
                        'kicker' => self::optionalText($sl['kicker'] ?? [], $locales, 80, "{$q}.kicker", false, $errors), 'title' => self::optionalText($sl['title'] ?? [], $locales, 100, "{$q}.title", false, $errors),
                        'subtitle' => self::optionalText($sl['subtitle'] ?? [], $locales, 200, "{$q}.subtitle", false, $errors), 'priceText' => self::optionalText($sl['priceText'] ?? [], $locales, 40, "{$q}.priceText", false, $errors),
                        'ctaLabel' => self::optionalText($sl['ctaLabel'] ?? [], $locales, 40, "{$q}.ctaLabel", false, $errors), 'ctaUrl' => $ctaUrl === '' ? null : $ctaUrl,
                        'align' => $sl['align'] ?? 'left', 'overlay' => (int) $overlay,
                    ];
                }

                return ['autoplay' => $int('autoplay', 0, 15, 6), 'slides' => $slides];

            case 'categories':
                return ['title' => $text('title', 80), 'subtitle' => $text('subtitle', 160), 'mode' => $enum('mode', ['auto', 'manual'], 'auto'), 'categoryIds' => $ids('categoryIds', 24, '/^[0-9a-zA-Z]{26}$/'), 'max' => $int('max', 2, 24, 10)];

            case 'products':
                $source = $enum('source', ['newest', 'sale', 'category', 'manual'], 'newest');
                $categoryId = $s['categoryId'] ?? null;
                $source !== 'category' || ($categoryId !== null && preg_match('/^[0-9a-zA-Z]{26}$/', (string) $categoryId) === 1) || $errors["{$p}.settings.categoryId"] = 'Elige la categoría.';
                $productIds = $ids('productIds', 24, '/^[0-9a-zA-Z]{26}$/');
                $source !== 'manual' || $productIds !== [] || $errors["{$p}.settings.productIds"] = 'Elige al menos un producto.';

                return ['title' => $text('title', 80), 'source' => $source, 'categoryId' => $categoryId, 'productIds' => $productIds, 'limit' => $int('limit', 2, 24, 10)];

            case 'banner':
                return ['image' => $media($s['image'] ?? null, 'image'), 'imageMobile' => $media($s['imageMobile'] ?? null, 'imageMobile'), 'title' => $text('title', 100), 'text' => $text('text', 240), 'ctaLabel' => $text('ctaLabel', 40),
                    'ctaUrl' => $url('ctaUrl'), 'align' => $enum('align', ['left', 'center', 'right'], 'left'), 'overlay' => $int('overlay', 0, 70, 20), 'height' => $enum('height', ['sm', 'md', 'lg'], 'md')];

            default: // text
                return ['title' => $text('title', 80), 'body' => $text('body', 2000)];
        }
    }

    /** @param list<string> $locales @param array<string, string> $errors @return array<string, string> */
    private static function optionalText(mixed $text, array $locales, int $max, string $path, bool $required, array &$errors): array
    {
        $out = [];
        foreach ((array) $text as $loc => $value) {
            if (! in_array($loc, $locales, true) || ! is_string($value) || mb_strlen(trim($value)) > $max) {
                $errors["{$path}.{$loc}"] = "Idioma no soportado o más de {$max} caracteres.";

                continue;
            }
            $out[$loc] = trim($value);
        }
        $required && array_filter($out) === [] && $errors[$path] = 'Obligatorio.';

        return $out;
    }
}
