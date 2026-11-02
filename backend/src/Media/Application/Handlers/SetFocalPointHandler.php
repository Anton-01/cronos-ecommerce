<?php

declare(strict_types=1);

namespace Cronos\Media\Application\Handlers;

use Cronos\Media\Domain\Events\MediaUploaded;
use Cronos\Media\Domain\Exceptions\InvalidImage;
use Cronos\Media\Domain\Exceptions\MediaNotFound;
use Cronos\Media\Domain\Ports\MediaRepository;
use Cronos\Shared\Domain\DomainEventDispatcher;

/** Marks where the subject of a picture is (0..1 from the top-left) and rebuilds the renditions so every crop keeps it in view. */
final class SetFocalPointHandler
{
    public function __construct(private readonly MediaRepository $media, private readonly DomainEventDispatcher $events) {}

    public function __invoke(string $id, float $x, float $y): void
    {
        $record = $this->media->find($id) ?? throw new MediaNotFound;
        $record->isImage() || throw new InvalidImage('Only pictures have a focal point.');
        ($x >= 0 && $x <= 1 && $y >= 0 && $y <= 1) || throw new InvalidImage('The focal point is a position between 0 and 1.');

        $this->media->setProperty($id, 'focal', ['x' => round($x, 4), 'y' => round($y, 4)]);
        $this->media->markPending($id);
        $this->events->dispatch(new MediaUploaded($id));
    }
}
