<?php

declare(strict_types=1);

namespace Cronos\Media\Application\Listeners;

use Cronos\Media\Application\Commands\ProcessMedia;
use Cronos\Media\Application\Handlers\ProcessMediaHandler;
use Cronos\Media\Domain\Events\MediaUploaded;
use Cronos\Media\Domain\Ports\MediaRepository;
use Illuminate\Contracts\Queue\ShouldQueue;

final class ProcessUploadedMedia implements ShouldQueue
{
    public string $queue = 'media';

    public bool $afterCommit = true;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function __construct(
        private readonly ProcessMediaHandler $handler,
        private readonly MediaRepository $media,
    ) {}

    public function handle(MediaUploaded $event): void
    {
        ($this->handler)(new ProcessMedia($event->mediaId));
    }

    /** Runs once every retry is exhausted so the library shows an honest status. */
    public function failed(MediaUploaded $event, \Throwable $exception): void
    {
        $this->media->markFailed($event->mediaId, mb_substr($exception->getMessage(), 0, 500));
    }
}
