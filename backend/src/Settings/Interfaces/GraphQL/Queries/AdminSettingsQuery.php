<?php

declare(strict_types=1);

namespace Cronos\Settings\Interfaces\GraphQL\Queries;

use Cronos\Settings\Application\SettingsSnapshot;

final class AdminSettingsQuery
{
    public function __construct(private readonly SettingsSnapshot $snapshot) {}

    /** Every key, private ones included. Served from the same Redis snapshot as the storefront. */
    public function __invoke(): array
    {
        return array_values(array_map(fn (array $e) => [
            'group' => $e['group'],
            'key' => $e['key'],
            'type' => $e['type'],
            'label' => $e['label'],
            'public' => $e['public'],
            'translatable' => $e['translatable'],
            'value' => $e['translatable'] ? null : ($e['type'] === 'media' ? $e['media'] : $e['value']),
            'translations' => array_values(array_map(
                fn (string $locale, ?string $text) => ['locale' => $locale, 'value' => $text],
                array_keys($e['translations']),
                array_values($e['translations']),
            )),
        ], $this->snapshot->get()));
    }
}
