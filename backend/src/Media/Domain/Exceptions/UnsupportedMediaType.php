<?php

declare(strict_types=1);

namespace Cronos\Media\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class UnsupportedMediaType extends DomainError
{
    public function __construct()
    {
        parent::__construct('This file type is not allowed.');
    }

    public function errorCode(): string
    {
        return 'UNSUPPORTED_MEDIA_TYPE';
    }
}
