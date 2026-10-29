<?php

declare(strict_types=1);

namespace Cronos\Settings\Application;

use Cronos\Settings\Domain\Ports\MediaLookup;
use Cronos\Settings\Domain\Ports\SettingsRepository;
use Cronos\Settings\Domain\SettingDefinitions;

/** Merges catalogue defaults, stored overrides and resolved media URLs into one read-optimised array. */
final class SettingsSnapshotBuilder
{
    public function __construct(
        private readonly SettingsRepository $repository,
        private readonly MediaLookup $media,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function build(): array
    {
        $stored = $this->repository->all();
        $mediaIds = [];

        foreach (SettingDefinitions::all() as $id => $definition) {
            if ($definition->type === 'media' && is_string($stored[$id]->value ?? null)) {
                $mediaIds[] = $stored[$id]->value;
            }
        }

        $urls = $mediaIds === [] ? [] : $this->media->urls($mediaIds);
        $snapshot = [];

        foreach (SettingDefinitions::all() as $id => $definition) {
            $override = $stored[$id] ?? null;
            $value = $definition->translatable ? null : ($override->value ?? $definition->default);

            $snapshot[$id] = [
                'group' => $definition->group,
                'key' => $definition->key,
                'type' => $definition->type,
                'label' => $definition->label,
                'public' => $definition->public,
                'translatable' => $definition->translatable,
                'value' => $value,
                'translations' => $definition->translatable ? ($override->translations ?? []) : [],
                'media' => $definition->type === 'media' && is_string($value) && isset($urls[$value])
                    ? ['id' => $value, 'url' => $urls[$value]]
                    : null,
            ];
        }

        return $snapshot;
    }
}
