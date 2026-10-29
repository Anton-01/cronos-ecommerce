<?php

declare(strict_types=1);

namespace Cronos\Media\Domain\Events;

final readonly class MediaUploaded
{
    public function __construct(public string $mediaId) {}
}
