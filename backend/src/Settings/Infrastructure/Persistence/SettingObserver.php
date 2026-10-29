<?php

declare(strict_types=1);

namespace Cronos\Settings\Infrastructure\Persistence;

use Cronos\Settings\Application\SettingsSnapshot;
use Cronos\Settings\Infrastructure\Persistence\Eloquent\SettingModel;
use Illuminate\Support\Facades\DB;

/** Safety net: any write that bypasses the handler (tinker, seeders, imports) still purges the cache. */
final class SettingObserver
{
    public function __construct(private readonly SettingsSnapshot $snapshot) {}

    public function saved(SettingModel $setting): void
    {
        $this->refreshAfterCommit();
    }

    public function deleted(SettingModel $setting): void
    {
        $this->refreshAfterCommit();
    }

    private function refreshAfterCommit(): void
    {
        DB::afterCommit(fn () => $this->snapshot->refresh());
    }
}
