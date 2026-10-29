<?php

declare(strict_types=1);

namespace Cronos\Catalog\Application;

use Cronos\Catalog\Domain\Exceptions\InvalidProduct;
use Cronos\Catalog\Domain\ValueObjects\Slug;

/** Normalises per-locale input (names, slugs) shared by every catalogue manager. */
final class Localized
{
    /** @return list<string> */
    public static function supported(): array
    {
        return array_values((array) config('app.supported_locales'));
    }

    public static function default(): string
    {
        return (string) config('app.locale_default');
    }

    /**
     * Cleans locale blocks: known locales only, trimmed text, slug derived from the name when omitted.
     *
     * @param  array<string, array<string, mixed>>  $input
     * @param  list<string>  $textFields  optional text columns to keep
     * @return array<string, array<string, ?string>>
     */
    public static function normalise(array $input, array $textFields = [], bool $requireDefault = true): array
    {
        $out = [];

        foreach ($input as $locale => $block) {
            in_array($locale, self::supported(), true) || throw new InvalidProduct("Locale [{$locale}] is not supported.");
            $name = trim((string) ($block['name'] ?? ''));
            $name !== '' || throw new InvalidProduct("The name in {$locale} cannot be empty.");
            mb_strlen($name) <= 255 || throw new InvalidProduct("The name in {$locale} is too long.");

            $row = ['name' => $name, 'slug' => (string) (isset($block['slug']) && trim((string) $block['slug']) !== '' ? Slug::fromValid(trim((string) $block['slug'])) : Slug::from($name))];
            foreach ($textFields as $field) {
                $value = isset($block[$field]) ? trim((string) $block[$field]) : null;
                $row[$field] = $value === '' ? null : $value;
            }
            $out[$locale] = $row;
        }

        ! $requireDefault || isset($out[self::default()]) || throw new InvalidProduct('The '.self::default().' translation is required.');

        return $out;
    }
}
