<?php

declare(strict_types=1);

namespace Cronos\Catalog\Interfaces\GraphQL;

use Illuminate\Support\Str;

/** Translates API shapes (camelCase, lists of translations) into what the application layer expects. */
final class Convert
{
    /** Recursively converts array keys from camelCase to snake_case. @param array<mixed> $data @return array<mixed> */
    public static function snake(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[is_string($key) ? Str::snake($key) : $key] = is_array($value) ? self::snake($value) : $value;
        }

        return $out;
    }

    /**
     * [{locale: "es-MX", name: "...", ...}] => ["es-MX" => ["name" => "...", ...]]
     *
     * @param  list<array<string, mixed>>  $list
     * @return array<string, array<string, mixed>>
     */
    public static function translations(array $list): array
    {
        $out = [];
        foreach ($list as $item) {
            $locale = (string) $item['locale'];
            unset($item['locale']);
            $out[$locale] = self::snake($item);
        }

        return $out;
    }

    /** [{locale, value}] => ["es-MX" => "value"] @param list<array{locale: string, value: string}> $list @return array<string, string> */
    public static function texts(array $list): array
    {
        return array_column($list, 'value', 'locale');
    }

    /** Camel-case a stored row for GraphQL output. @param array<string, mixed> $row @return array<string, mixed> */
    public static function camel(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $out[Str::camel((string) $key)] = $value;
        }

        return $out;
    }
}
