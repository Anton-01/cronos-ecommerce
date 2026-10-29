<?php

declare(strict_types=1);

namespace Cronos\Settings\Application;

/** Storefront view of the snapshot: public keys only, translated with fallback. */
final class PublicSettingsReader
{
    public function __construct(private readonly SettingsSnapshot $snapshot) {}

    /** @return list<array{group: string, key: string, type: string, value: mixed}> */
    public function read(?string $locale): array
    {
        $supported = (array) config('app.supported_locales');
        $fallback = (string) config('app.locale_default');
        $locale = in_array($locale, $supported, true) ? $locale : $fallback;

        $out = [];
        foreach ($this->snapshot->get() as $entry) {
            if (! $entry['public']) {
                continue;
            }

            $value = match (true) {
                $entry['translatable'] => $this->translated($entry['translations'], $locale, $fallback),
                $entry['type'] === 'media' => $entry['media'],
                default => $entry['value'],
            };

            $out[] = ['group' => $entry['group'], 'key' => $entry['key'], 'type' => $entry['type'], 'value' => $value];
        }

        return $out;
    }

    /** @param array<string, ?string> $translations */
    private function translated(array $translations, string $locale, string $fallback): ?string
    {
        foreach ([$locale, $fallback] as $candidate) {
            if (isset($translations[$candidate]) && $translations[$candidate] !== '') {
                return $translations[$candidate];
            }
        }

        return null;
    }
}
