<?php

declare(strict_types=1);

namespace Cronos\Media\Infrastructure;

use Cronos\Media\Application\Listeners\ProcessUploadedMedia;
use Cronos\Media\Domain\Events\MediaUploaded;
use Cronos\Media\Domain\Ports\ImageProcessor;
use Cronos\Media\Domain\Ports\MalwareScanner;
use Cronos\Media\Domain\Ports\MediaRepository;
use Cronos\Media\Domain\Ports\MediaStorage;
use Cronos\Media\Domain\Ports\MimeSniffer;
use Cronos\Media\Infrastructure\Adapters\ClamAvMalwareScanner;
use Cronos\Media\Infrastructure\Adapters\FinfoMimeSniffer;
use Cronos\Media\Infrastructure\Adapters\GdImageProcessor;
use Cronos\Media\Infrastructure\Adapters\LaravelMediaStorage;
use Cronos\Media\Infrastructure\Adapters\NullMalwareScanner;
use Cronos\Media\Infrastructure\Console\PurgeMediaCommand;
use Cronos\Media\Infrastructure\Persistence\EloquentMediaRepository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class MediaServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        MediaRepository::class => EloquentMediaRepository::class,
        MediaStorage::class => LaravelMediaStorage::class,
        ImageProcessor::class => GdImageProcessor::class,
        MimeSniffer::class => FinfoMimeSniffer::class,
    ];

    public function register(): void
    {
        $this->app->bind(MalwareScanner::class, fn () => config('media.antivirus.driver') === 'clamav' ? new ClamAvMalwareScanner : new NullMalwareScanner);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PurgeMediaCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('media:purge')->dailyAt('03:40')->withoutOverlapping()->onOneServer();
        });

        Event::listen(MediaUploaded::class, ProcessUploadedMedia::class);
    }
}
