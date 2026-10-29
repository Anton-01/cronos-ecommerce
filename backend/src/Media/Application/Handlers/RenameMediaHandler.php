<?php

declare(strict_types=1);

namespace Cronos\Media\Application\Handlers;

use Cronos\Media\Application\Commands\RenameMedia;
use Cronos\Media\Domain\Exceptions\MediaNotFound;
use Cronos\Media\Domain\Ports\MediaRepository;

final class RenameMediaHandler
{
    public function __construct(private readonly MediaRepository $media) {}

    public function __invoke(RenameMedia $command): void
    {
        $this->media->find($command->id) ?? throw new MediaNotFound;
        // Only the display name changes; the stored file name stays immutable so URLs never break.
        $this->media->rename($command->id, trim($command->name));
    }
}
