<?php

declare(strict_types=1);

namespace Cronos\Media\Application\Handlers;

use Cronos\Media\Application\Commands\DeleteMedia;
use Cronos\Media\Domain\Exceptions\MediaNotFound;
use Cronos\Media\Domain\Ports\MediaRepository;

final class DeleteMediaHandler
{
    public function __construct(private readonly MediaRepository $media) {}

    public function __invoke(DeleteMedia $command): void
    {
        $this->media->find($command->id) ?? throw new MediaNotFound;
        $this->media->delete($command->id);
    }
}
