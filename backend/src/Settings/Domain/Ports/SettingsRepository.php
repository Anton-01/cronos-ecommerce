<?php

declare(strict_types=1);

namespace Cronos\Settings\Domain\Ports;

use Cronos\Settings\Domain\ValueObjects\StoredSetting;

interface SettingsRepository
{
    /** @return array<string, StoredSetting> keyed by "group.key" */
    public function all(): array;

    /** @param array<string, ?string> $translations Replaces every stored translation of the key. */
    public function save(string $group, string $key, mixed $value, array $translations, ?string $updatedBy): void;

    /** Removes the override so the default applies again. */
    public function reset(string $group, string $key): void;
}
