<?php

declare(strict_types=1);

namespace Cronos\Media\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class MediaNotFound extends DomainError
{
    public function __construct()
    {
        parent::__construct('The media item does not exist.');
    }

    public function errorCode(): string
    {
        return 'MEDIA_NOT_FOUND';
    }
}
