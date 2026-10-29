<?php

declare(strict_types=1);

namespace Cronos\Settings\Application;

use Cronos\Settings\Domain\Ports\SettingsSnapshotStore;

/** Single entry point for reading settings: Redis first, rebuilt from the database only when cold. */
final class SettingsSnapshot
{
    public function __construct(
        private readonly SettingsSnapshotStore $store,
        private readonly SettingsSnapshotBuilder $builder,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function get(): array
    {
        return $this->store->remember(fn () => $this->builder->build());
    }

    /** Write-through: replaces the cached copy with a fresh build (called after every change). */
    public function refresh(): void
    {
        $this->store->put($this->builder->build());
    }

    public function referencesMedia(string $mediaId): bool
    {
        foreach ($this->get() as $entry) {
            if ($entry['type'] === 'media' && $entry['value'] === $mediaId) {
                return true;
            }
        }

        return false;
    }
}
