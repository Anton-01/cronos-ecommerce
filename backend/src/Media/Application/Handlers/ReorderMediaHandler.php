<?php

declare(strict_types=1);

namespace Cronos\Media\Application\Handlers;

use Cronos\Media\Application\Commands\ReorderMedia;
use Cronos\Media\Domain\Ports\MediaRepository;

final class ReorderMediaHandler
{
    public function __construct(private readonly MediaRepository $media) {}

    public function __invoke(ReorderMedia $command): void
    {
        $this->media->reorder($command->ownerType, $command->ownerId, $command->collection, $command->orderedIds);
    }
}
