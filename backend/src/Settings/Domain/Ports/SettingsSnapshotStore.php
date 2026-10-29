<?php

declare(strict_types=1);

namespace Cronos\Settings\Domain\Ports;

interface SettingsSnapshotStore
{
    /** @return array<string, array<string, mixed>>|null */
    public function get(): ?array;

    /** @param array<string, array<string, mixed>> $snapshot Stored with no expiry. */
    public function put(array $snapshot): void;

    public function forget(): void;

    /** Builds under a lock so a cold cache does not trigger a stampede of rebuilds. @param callable():array<string, array<string, mixed>> $build */
    public function remember(callable $build): array;
}
