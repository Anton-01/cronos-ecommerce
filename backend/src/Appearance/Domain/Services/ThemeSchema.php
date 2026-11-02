<?php

declare(strict_types=1);

namespace Cronos\Appearance\Domain\Services;

use Cronos\Appearance\Domain\Exceptions\ThemeError;

/**
 * What a theme may contain, with its defaults (the look of the reference store) and the rules for each setting. Anything unknown is
 * refused, anything out of range is refused with the field named: a theme saved here can always be rendered.
 */
final class ThemeSchema
{
    public const COLORS = [
        'primary' => ['#F59A57', 'Color principal (precios, botón principal, acentos)'],
        'onPrimary' => [null, 'Texto sobre el color principal (vacío = automático)'],
        'secondary' => ['#242424', 'Color secundario (botón oscuro, carrito)'],
        'onSecondary' => [null, 'Texto sobre el secundario (vacío = automático)'],
        'background' => ['#F3F2EE', 'Fondo de la página'],
        'surface' => ['#FFFFFF', 'Tarjetas y paneles'],
        'text' => ['#767676', 'Texto'],
        'heading' => ['#242424', 'Títulos'],
        'link' => ['#333333', 'Enlaces'],
        'border' => ['#E4E2DC', 'Líneas y bordes'],
        'success' => ['#438E44', 'Éxito y etiqueta "Nuevo"'],
        'danger' => ['#D9362B', 'Errores y etiqueta "Hot"'],
        'sale' => ['#F59A57', 'Descuentos y precio rebajado'],
        'headerBg' => ['#FBFAF7', 'Fondo del encabezado'],
        'headerText' => ['#333333', 'Texto del encabezado'],
        'topbarBg' => ['#E6E5E1', 'Fondo de la barra superior'],
        'topbarText' => ['#767676', 'Texto de la barra superior'],
        'ribbonBg' => ['#E0EAF0', 'Fondo de la cinta de avisos'],
        'ribbonText' => ['#333333', 'Texto de la cinta de avisos'],
        'footerBg' => ['#000000', 'Fondo del pie de página'],
        'footerText' => ['#CCCCCC', 'Texto del pie de página'],
        'footerHeading' => ['#FFFFFF', 'Títulos del pie de página'],
    ];

    /** Google Fonts the shop may use (self-hosting them is a deployment choice); "system" costs no download at all. */
    public const FONTS = [
        'system' => ['label' => 'Sistema (sin descarga)', 'stack' => 'system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif', 'weights' => [400, 500, 600, 700]],
        'Urbanist' => ['label' => 'Urbanist', 'stack' => '"Urbanist", system-ui, sans-serif', 'weights' => [400, 500, 600, 700, 800]],
        'Work Sans' => ['label' => 'Work Sans', 'stack' => '"Work Sans", system-ui, sans-serif', 'weights' => [400, 500, 600, 700]],
        'Inter' => ['label' => 'Inter', 'stack' => '"Inter", system-ui, sans-serif', 'weights' => [400, 500, 600, 700, 800]],
        'Poppins' => ['label' => 'Poppins', 'stack' => '"Poppins", system-ui, sans-serif', 'weights' => [400, 500, 600, 700]],
        'Montserrat' => ['label' => 'Montserrat', 'stack' => '"Montserrat", system-ui, sans-serif', 'weights' => [400, 500, 600, 700, 800]],
        'DM Sans' => ['label' => 'DM Sans', 'stack' => '"DM Sans", system-ui, sans-serif', 'weights' => [400, 500, 600, 700]],
        'Nunito' => ['label' => 'Nunito', 'stack' => '"Nunito", system-ui, sans-serif', 'weights' => [400, 600, 700, 800]],
        'Manrope' => ['label' => 'Manrope', 'stack' => '"Manrope", system-ui, sans-serif', 'weights' => [400, 500, 600, 700, 800]],
        'Raleway' => ['label' => 'Raleway', 'stack' => '"Raleway", system-ui, sans-serif', 'weights' => [400, 500, 600, 700, 800]],
        'Open Sans' => ['label' => 'Open Sans', 'stack' => '"Open Sans", system-ui, sans-serif', 'weights' => [400, 600, 700, 800]],
        'Roboto' => ['label' => 'Roboto', 'stack' => '"Roboto", system-ui, sans-serif', 'weights' => [400, 500, 700]],
        'Playfair Display' => ['label' => 'Playfair Display (serif)', 'stack' => '"Playfair Display", Georgia, serif', 'weights' => [400, 500, 600, 700, 800]],
        'Lora' => ['label' => 'Lora (serif)', 'stack' => '"Lora", Georgia, serif', 'weights' => [400, 500, 600, 700]],
        'Merriweather' => ['label' => 'Merriweather (serif)', 'stack' => '"Merriweather", Georgia, serif', 'weights' => [400, 700]],
        'Cormorant Garamond' => ['label' => 'Cormorant Garamond (serif)', 'stack' => '"Cormorant Garamond", Georgia, serif', 'weights' => [400, 500, 600, 700]],
    ];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'colors' => array_map(static fn (array $c) => $c[0], self::COLORS),
            'typography' => ['headingFont' => 'Urbanist', 'bodyFont' => 'Work Sans', 'headingWeight' => 700, 'bodyWeight' => 400, 'baseSize' => 16, 'lineHeight' => 1.6, 'headingTransform' => 'none', 'letterSpacing' => 0],
            'shape' => ['radiusCard' => 10, 'radiusButton' => 30, 'radiusInput' => 30, 'radiusBadge' => 12, 'categoryShape' => 'circle', 'cardStyle' => 'flat', 'buttonStyle' => 'filled'],
            'layout' => ['containerWidth' => 1400, 'density' => 'comfortable', 'gridColumnsDesktop' => 4, 'gridColumnsMobile' => 2, 'headerStyle' => 'classic', 'showTopbar' => true, 'showCategoryNav' => true, 'stickyHeader' => true, 'backgroundTexture' => 'grain'],
            'identity' => ['siteName' => 'Cronos', 'tagline' => ['es-MX' => '', 'en-US' => ''], 'themeColor' => null],
        ];
    }

    /**
     * Fills in what is missing and checks what is given.
     *
     * @param  array<string, mixed>  $input  any subset of the token groups
     * @return array<string, mixed> the complete, normalised tokens
     */
    public static function normalize(array $input): array
    {
        $errors = [];
        $out = self::defaults();
        $unknownGroups = array_diff(array_keys($input), array_keys($out));
        foreach ($unknownGroups as $g) {
            $errors[$g] = 'Grupo desconocido.';
        }

        foreach ((array) ($input['colors'] ?? []) as $key => $value) {
            if (! array_key_exists($key, self::COLORS)) {
                $errors["colors.{$key}"] = 'Color desconocido.';

                continue;
            }
            if ($value === null || $value === '') {
                $out['colors'][$key] = self::COLORS[$key][0]; // cleared = back to the default (null = automatic)

                continue;
            }
            try {
                $out['colors'][$key] = Color::normalize((string) $value);
            } catch (ThemeError $e) {
                $errors["colors.{$key}"] = $e->getMessage();
            }
        }

        $t = (array) ($input['typography'] ?? []);
        foreach (array_diff(array_keys($t), array_keys($out['typography'])) as $k) {
            $errors["typography.{$k}"] = 'Ajuste desconocido.';
        }
        foreach (['headingFont', 'bodyFont'] as $k) {
            if (isset($t[$k])) {
                isset(self::FONTS[$t[$k]]) ? $out['typography'][$k] = $t[$k] : $errors["typography.{$k}"] = 'Tipografía no disponible.';
            }
        }
        foreach (['headingWeight' => 'headingFont', 'bodyWeight' => 'bodyFont'] as $k => $fontKey) {
            if (isset($t[$k])) {
                $font = $out['typography'][$fontKey];
                in_array((int) $t[$k], self::FONTS[$font]['weights'], true) ? $out['typography'][$k] = (int) $t[$k] : $errors["typography.{$k}"] = "{$font} no tiene el grosor {$t[$k]}.";
            }
        }
        foreach (['headingWeight' => 'headingFont', 'bodyWeight' => 'bodyFont'] as $k => $fontKey) {
            // A font that does not have the default weight falls to its nearest one instead of leaving the theme unrenderable.
            $weights = self::FONTS[$out['typography'][$fontKey]]['weights'];
            in_array($out['typography'][$k], $weights, true) || $out['typography'][$k] = self::nearest($out['typography'][$k], $weights);
        }
        self::number($t, 'baseSize', 13, 20, $out['typography'], $errors, 'typography');
        self::number($t, 'lineHeight', 1.2, 2.0, $out['typography'], $errors, 'typography', true);
        self::number($t, 'letterSpacing', -1, 3, $out['typography'], $errors, 'typography', true);
        self::choice($t, 'headingTransform', ['none', 'capitalize', 'uppercase'], $out['typography'], $errors, 'typography');

        $s = (array) ($input['shape'] ?? []);
        foreach (array_diff(array_keys($s), array_keys($out['shape'])) as $k) {
            $errors["shape.{$k}"] = 'Ajuste desconocido.';
        }
        foreach (['radiusCard' => 24, 'radiusButton' => 40, 'radiusInput' => 40, 'radiusBadge' => 20] as $k => $max) {
            self::number($s, $k, 0, $max, $out['shape'], $errors, 'shape');
        }
        self::choice($s, 'categoryShape', ['circle', 'rounded', 'square'], $out['shape'], $errors, 'shape');
        self::choice($s, 'cardStyle', ['flat', 'bordered', 'shadow'], $out['shape'], $errors, 'shape');
        self::choice($s, 'buttonStyle', ['filled', 'outline'], $out['shape'], $errors, 'shape');

        $l = (array) ($input['layout'] ?? []);
        foreach (array_diff(array_keys($l), array_keys($out['layout'])) as $k) {
            $errors["layout.{$k}"] = 'Ajuste desconocido.';
        }
        self::number($l, 'containerWidth', 1100, 1680, $out['layout'], $errors, 'layout');
        self::number($l, 'gridColumnsDesktop', 3, 5, $out['layout'], $errors, 'layout');
        self::number($l, 'gridColumnsMobile', 1, 2, $out['layout'], $errors, 'layout');
        self::choice($l, 'density', ['comfortable', 'compact'], $out['layout'], $errors, 'layout');
        self::choice($l, 'headerStyle', ['classic', 'centered'], $out['layout'], $errors, 'layout');
        self::choice($l, 'backgroundTexture', ['grain', 'none'], $out['layout'], $errors, 'layout');
        foreach (['showTopbar', 'showCategoryNav', 'stickyHeader'] as $k) {
            if (array_key_exists($k, $l)) {
                is_bool($l[$k]) ? $out['layout'][$k] = $l[$k] : $errors["layout.{$k}"] = 'Debe ser sí o no.';
            }
        }

        $i = (array) ($input['identity'] ?? []);
        foreach (array_diff(array_keys($i), array_keys($out['identity'])) as $k) {
            $errors["identity.{$k}"] = 'Ajuste desconocido.';
        }
        if (isset($i['siteName'])) {
            $name = trim((string) $i['siteName']);
            mb_strlen($name) >= 1 && mb_strlen($name) <= 60 ? $out['identity']['siteName'] = $name : $errors['identity.siteName'] = 'El nombre de la tienda tiene de 1 a 60 caracteres.';
        }
        if (isset($i['tagline'])) {
            foreach ((array) $i['tagline'] as $loc => $text) {
                (in_array($loc, (array) config('app.supported_locales'), true) && is_string($text) && mb_strlen($text) <= 140) ? $out['identity']['tagline'][$loc] = trim($text) : $errors["identity.tagline.{$loc}"] = 'Idioma no soportado o texto de más de 140 caracteres.';
            }
        }
        if (array_key_exists('themeColor', $i)) {
            try {
                $out['identity']['themeColor'] = $i['themeColor'] === null || $i['themeColor'] === '' ? null : Color::normalize((string) $i['themeColor']);
            } catch (ThemeError $e) {
                $errors['identity.themeColor'] = $e->getMessage();
            }
        }

        $errors === [] || throw new ThemeError('El tema tiene valores que no se pueden usar.', 'INVALID_THEME', $errors);

        return $out;
    }

    /**
     * Readability problems of the colours chosen (WCAG AA). They are advice, not errors: a brand may choose what it likes.
     *
     * @param  array<string, mixed>  $tokens
     * @return list<array{pair: string, ratio: float, needed: float, ok: bool}>
     */
    public static function contrast(array $tokens): array
    {
        $c = $tokens['colors'];
        $onPrimary = $c['onPrimary'] ?? Color::readableOn($c['primary']);
        $onSecondary = $c['onSecondary'] ?? Color::readableOn($c['secondary']);
        $pairs = [
            'Texto sobre el fondo' => [$c['text'], $c['background'], 4.5],
            'Texto sobre las tarjetas' => [$c['text'], $c['surface'], 4.5],
            'Títulos sobre el fondo' => [$c['heading'], $c['background'], 4.5],
            'Botón principal' => [$onPrimary, $c['primary'], 4.5],
            'Botón secundario' => [$onSecondary, $c['secondary'], 4.5],
            'Precio (color principal) sobre las tarjetas' => [$c['primary'], $c['surface'], 3.0],
            'Enlaces sobre las tarjetas' => [$c['link'], $c['surface'], 4.5],
            'Encabezado' => [$c['headerText'], $c['headerBg'], 4.5],
            'Barra superior' => [$c['topbarText'], $c['topbarBg'], 4.5],
            'Cinta de avisos' => [$c['ribbonText'], $c['ribbonBg'], 4.5],
            'Pie de página' => [$c['footerText'], $c['footerBg'], 4.5],
            'Títulos del pie de página' => [$c['footerHeading'], $c['footerBg'], 4.5],
        ];
        $out = [];
        foreach ($pairs as $name => [$fg, $bg, $needed]) {
            $ratio = round(Color::contrast($fg, $bg), 2);
            $out[] = ['pair' => $name, 'ratio' => $ratio, 'needed' => $needed, 'ok' => $ratio >= $needed];
        }

        return $out;
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $target @param array<string, string> $errors */
    private static function number(array $source, string $key, float|int $min, float|int $max, array &$target, array &$errors, string $group, bool $float = false): void
    {
        if (! array_key_exists($key, $source)) {
            return;
        }
        $v = $source[$key];
        if (! is_numeric($v) || $v < $min || $v > $max) {
            $errors["{$group}.{$key}"] = "Debe estar entre {$min} y {$max}.";

            return;
        }
        $target[$key] = $float ? round((float) $v, 2) : (int) $v;
    }

    /** @param array<string, mixed> $source @param list<string> $allowed @param array<string, mixed> $target @param array<string, string> $errors */
    private static function choice(array $source, string $key, array $allowed, array &$target, array &$errors, string $group): void
    {
        if (array_key_exists($key, $source)) {
            in_array($source[$key], $allowed, true) ? $target[$key] = $source[$key] : $errors["{$group}.{$key}"] = 'Opción no válida: '.implode(', ', $allowed).'.';
        }
    }

    /** @param list<int> $weights */
    private static function nearest(int $wanted, array $weights): int
    {
        usort($weights, static fn (int $a, int $b) => abs($a - $wanted) <=> abs($b - $wanted));

        return $weights[0];
    }
}
