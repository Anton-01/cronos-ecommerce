<?php

declare(strict_types=1);

namespace Cronos\Settings\Infrastructure;

use Cronos\Media\Domain\Events\MediaChanged;
use Cronos\Settings\Application\Listeners\RefreshSettingsWhenMediaChanges;
use Cronos\Settings\Domain\Ports\MediaLookup;
use Cronos\Settings\Domain\Ports\SettingsRepository;
use Cronos\Settings\Domain\Ports\SettingsSnapshotStore;
use Cronos\Settings\Infrastructure\Adapters\CacheSettingsSnapshotStore;
use Cronos\Settings\Infrastructure\Adapters\MediaLibraryLookup;
use Cronos\Settings\Infrastructure\Console\WarmSettingsCommand;
use Cronos\Settings\Infrastructure\Persistence\Eloquent\SettingModel;
use Cronos\Settings\Infrastructure\Persistence\EloquentSettingsRepository;
use Cronos\Settings\Infrastructure\Persistence\SettingObserver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class SettingsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        SettingsRepository::class => EloquentSettingsRepository::class,
        SettingsSnapshotStore::class => CacheSettingsSnapshotStore::class,
        MediaLookup::class => MediaLibraryLookup::class,
    ];

    public function boot(): void
    {
        SettingModel::observe(SettingObserver::class);
        Event::listen(MediaChanged::class, [RefreshSettingsWhenMediaChanges::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([WarmSettingsCommand::class]);
        }
    }
}
