<?php

declare(strict_types=1);

namespace Cronos\Appearance\Domain\Services;

use Cronos\Appearance\Domain\Exceptions\ThemeError;

/** Menus, social links, the announcement ribbon and the footer: validated so a link can never be a script or a broken shape. */
final class NavigationSchema
{
    public const SOCIAL = ['facebook', 'instagram', 'x', 'youtube', 'tiktok', 'pinterest', 'whatsapp', 'linkedin'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'topbarLinks' => [],
            'footerColumns' => [],
            'social' => [],
            'announcements' => [],
            'showFreeShippingRibbon' => true,
            'footerNote' => ['es-MX' => '', 'en-US' => ''],
        ];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public static function normalize(array $in, array $locales): array
    {
        $errors = [];
        $out = self::defaults();
        foreach (array_diff(array_keys($in), array_keys($out)) as $k) {
            $errors[$k] = 'Ajuste desconocido.';
        }

        foreach (['topbarLinks' => 8] as $key => $max) {
            if (array_key_exists($key, $in)) {
                $out[$key] = self::links((array) $in[$key], $locales, $max, $key, $errors);
            }
        }

        if (array_key_exists('footerColumns', $in)) {
            $cols = [];
            foreach (array_values((array) $in['footerColumns']) as $i => $col) {
                if ($i >= 4) {
                    $errors['footerColumns'] = 'Máximo 4 columnas.';
                    break;
                }
                $cols[] = ['title' => self::text($col['title'] ?? [], $locales, 60, "footerColumns.{$i}.title", $errors), 'links' => self::links((array) ($col['links'] ?? []), $locales, 10, "footerColumns.{$i}.links", $errors)];
            }
            $out['footerColumns'] = $cols;
        }

        if (array_key_exists('social', $in)) {
            foreach ((array) $in['social'] as $network => $url) {
                if (! in_array($network, self::SOCIAL, true)) {
                    $errors["social.{$network}"] = 'Red no disponible.';
                } elseif ($url !== null && $url !== '') {
                    self::absoluteUrl((string) $url) ? $out['social'][$network] = trim((string) $url) : $errors["social.{$network}"] = 'Debe ser una dirección https://…';
                }
            }
        }

        if (array_key_exists('announcements', $in)) {
            $items = [];
            foreach (array_values((array) $in['announcements']) as $i => $a) {
                if ($i >= 5) {
                    $errors['announcements'] = 'Máximo 5 avisos.';
                    break;
                }
                $markets = array_values(array_unique(array_map('strtoupper', (array) ($a['markets'] ?? []))));
                foreach ($markets as $m) {
                    preg_match('/^[A-Z0-9]{2,12}$/', $m) === 1 || $errors["announcements.{$i}.markets"] = "Tienda no válida: {$m}.";
                }
                $url = $a['url'] ?? null;
                ($url === null || $url === '' || self::linkOk((string) $url)) || $errors["announcements.{$i}.url"] = 'Enlace no válido.';
                $items[] = ['markets' => $markets, 'text' => self::text($a['text'] ?? [], $locales, 160, "announcements.{$i}.text", $errors), 'url' => $url === '' ? null : $url];
            }
            $out['announcements'] = $items;
        }

        if (array_key_exists('showFreeShippingRibbon', $in)) {
            is_bool($in['showFreeShippingRibbon']) ? $out['showFreeShippingRibbon'] = $in['showFreeShippingRibbon'] : $errors['showFreeShippingRibbon'] = 'Debe ser sí o no.';
        }
        if (array_key_exists('footerNote', $in)) {
            $out['footerNote'] = self::text((array) $in['footerNote'], $locales, 240, 'footerNote', $errors, false) + $out['footerNote'];
        }

        $errors === [] || throw new ThemeError('La navegación tiene valores que no se pueden usar.', 'INVALID_THEME', $errors);

        return $out;
    }

    /** @param array<string, mixed> $links @param list<string> $locales @param array<string, string> $errors @return list<array{label: array<string, string>, url: string}> */
    private static function links(array $links, array $locales, int $max, string $path, array &$errors): array
    {
        $out = [];
        foreach (array_values($links) as $i => $l) {
            if ($i >= $max) {
                $errors[$path] = "Máximo {$max} enlaces.";
                break;
            }
            $url = trim((string) ($l['url'] ?? ''));
            self::linkOk($url) || $errors["{$path}.{$i}.url"] = 'Debe empezar con / (página de la tienda) o https://';
            $out[] = ['label' => self::text($l['label'] ?? [], $locales, 60, "{$path}.{$i}.label", $errors), 'url' => $url];
        }

        return $out;
    }

    /** One text per language; at least one must be filled. @param array<string, mixed> $text @param list<string> $locales @param array<string, string> $errors @return array<string, string> */
    public static function text(mixed $text, array $locales, int $max, string $path, array &$errors, bool $required = true): array
    {
        $out = [];
        foreach ((array) $text as $loc => $value) {
            if (! in_array($loc, $locales, true) || ! is_string($value) || mb_strlen(trim($value)) > $max) {
                $errors["{$path}.{$loc}"] = "Idioma no soportado o texto de más de {$max} caracteres.";

                continue;
            }
            $out[$loc] = trim($value);
        }
        ! $required || array_filter($out) !== [] || $errors[$path] = 'Escribe el texto en al menos un idioma.';

        return $out;
    }

    /** A page of the store ("/es-mx/ofertas", "/contact") or a secure absolute address. Never javascript:, data: or protocol-relative. */
    public static function linkOk(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > 300 || preg_match('/[\x00-\x1F\s]/', $url) === 1) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//') && ! str_contains($url, '\\');
        }

        return self::absoluteUrl($url);
    }

    public static function absoluteUrl(string $url): bool
    {
        $url = trim($url);
        $parts = parse_url($url);

        return $parts !== false && ($parts['scheme'] ?? '') === 'https' && ! empty($parts['host']) && mb_strlen($url) <= 300 && preg_match('/[\x00-\x1F\s]/', $url) !== 1;
    }
}
