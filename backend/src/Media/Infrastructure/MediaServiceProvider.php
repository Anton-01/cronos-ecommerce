<?php

declare(strict_types=1);

namespace Cronos\Media\Infrastructure;

use Cronos\Media\Application\Listeners\ProcessUploadedMedia;
use Cronos\Media\Domain\Events\MediaUploaded;
use Cronos\Media\Domain\Ports\ImageProcessor;
use Cronos\Media\Domain\Ports\MediaRepository;
use Cronos\Media\Domain\Ports\MediaStorage;
use Cronos\Media\Domain\Ports\MimeSniffer;
use Cronos\Media\Infrastructure\Adapters\FinfoMimeSniffer;
use Cronos\Media\Infrastructure\Adapters\GdImageProcessor;
use Cronos\Media\Infrastructure\Adapters\LaravelMediaStorage;
use Cronos\Media\Infrastructure\Persistence\EloquentMediaRepository;
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

    public function boot(): void
    {
        Event::listen(MediaUploaded::class, ProcessUploadedMedia::class);
    }
}
