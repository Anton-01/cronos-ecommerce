<?php

declare(strict_types=1);

namespace Cronos\Media\Application\Handlers;

use Cronos\Media\Application\Commands\AttachMedia;
use Cronos\Media\Domain\Exceptions\MediaNotFound;
use Cronos\Media\Domain\Ports\MediaRepository;

final class AttachMediaHandler
{
    public function __construct(private readonly MediaRepository $media) {}

    public function __invoke(AttachMedia $command): void
    {
        $this->media->find($command->id) ?? throw new MediaNotFound;

        $this->media->attach(
            $command->id,
            $command->ownerType,
            $command->ownerId,
            $command->collection,
            replaceExisting: in_array($command->collection, (array) config('media.single_file_collections'), true),
        );
    }
}
