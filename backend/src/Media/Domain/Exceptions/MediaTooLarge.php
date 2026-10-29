<?php

declare(strict_types=1);

namespace Cronos\Media\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class MediaTooLarge extends DomainError
{
    public function __construct(int $maxKb)
    {
        parent::__construct("The file exceeds the {$maxKb} KB limit.");
    }

    public function errorCode(): string
    {
        return 'MEDIA_TOO_LARGE';
    }
}
