<?php

declare(strict_types=1);

namespace Cronos\Content\Domain\Services;

use Cronos\Content\Domain\Exceptions\ContentError;

/** What makes a page well formed. Pure: no database, no framework. */
final class PageRules
{
    public const MAX_BODY = 60000;

    /** "Política de envíos" → "politica-de-envios": the address of the page, lower case, no accents, safe in a URL. */
    public static function slugify(string $text): string
    {
        $folded = strtr(mb_strtolower($text), ['á' => 'a', 'à' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'í' => 'i', 'ï' => 'i', 'ó' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c']);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $folded), '-');

        return substr($slug, 0, 80);
    }

    public static function assertKey(string $key): string
    {
        preg_match('/^[a-z0-9][a-z0-9-]{0,58}$/', $key) === 1 || throw new ContentError('The page key may only have lower case letters, numbers and dashes.', 'INVALID_PAGE', ['key' => 'Solo minúsculas, números y guiones.']);

        return $key;
    }

    /**
     * @param  array<string, array<string, mixed>>  $translations  by locale
     * @param  list<string>  $locales  the languages the shop speaks
     * @return array<string, array{slug: string, title: string, body_md: string, seo_title: ?string, seo_description: ?string}>
     */
    public static function normalizeTranslations(array $translations, array $locales): array
    {
        $out = [];
        $errors = [];
        foreach ($translations as $locale => $t) {
            if (! in_array($locale, $locales, true)) {
                $errors["translations.{$locale}"] = 'Idioma no soportado.';

                continue;
            }
            $title = trim((string) ($t['title'] ?? ''));
            $body = (string) ($t['body_md'] ?? $t['bodyMd'] ?? '');
            $slug = self::slugify((string) (($t['slug'] ?? '') !== '' ? $t['slug'] : $title));
            $title === '' && $errors["translations.{$locale}.title"] = 'El título es obligatorio.';
            $slug === '' && $errors["translations.{$locale}.slug"] = 'La dirección (slug) es obligatoria.';
            mb_strlen($title) > 160 && $errors["translations.{$locale}.title"] = 'Máximo 160 caracteres.';
            strlen($body) > self::MAX_BODY && $errors["translations.{$locale}.body"] = 'El texto es demasiado largo.';
            $seoTitle = trim((string) ($t['seo_title'] ?? $t['seoTitle'] ?? ''));
            $seoDescription = trim((string) ($t['seo_description'] ?? $t['seoDescription'] ?? ''));
            $out[$locale] = ['slug' => $slug, 'title' => $title, 'body_md' => $body, 'seo_title' => $seoTitle === '' ? null : mb_substr($seoTitle, 0, 160), 'seo_description' => $seoDescription === '' ? null : mb_substr($seoDescription, 0, 320)];
        }
        $out !== [] || $errors['translations'] = 'Escribe la página en al menos un idioma.';
        $errors === [] || throw new ContentError('The page is not valid.', 'INVALID_PAGE', $errors);

        return $out;
    }

    /** @param  list<string>  $markets @return list<string> */
    public static function normalizeMarkets(array $markets): array
    {
        $codes = array_values(array_unique(array_map(static fn ($m) => strtoupper(trim((string) $m)), $markets)));
        foreach ($codes as $c) {
            preg_match('/^[A-Z]{2,8}$/', $c) === 1 || throw new ContentError('Invalid store code.', 'INVALID_PAGE', ['markets' => 'Código de tienda no válido.']);
        }

        return $codes;
    }
}
