<?php

declare(strict_types=1);

namespace Cronos\Media\Application\Handlers;

use Cronos\Media\Application\Commands\DetachMedia;
use Cronos\Media\Domain\Exceptions\MediaNotFound;
use Cronos\Media\Domain\Ports\MediaRepository;

final class DetachMediaHandler
{
    public function __construct(private readonly MediaRepository $media) {}

    public function __invoke(DetachMedia $command): void
    {
        $this->media->find($command->id) ?? throw new MediaNotFound;
        $this->media->detach($command->id);
    }
}
