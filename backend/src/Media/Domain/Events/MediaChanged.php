<?php

declare(strict_types=1);

namespace Cronos\Media\Domain\Events;

/** Emitted whenever a media row is created, updated or deleted, so dependants can refresh caches. */
final readonly class MediaChanged
{
    public function __construct(public string $mediaId) {}
}
