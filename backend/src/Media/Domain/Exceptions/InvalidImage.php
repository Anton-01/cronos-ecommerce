<?php

declare(strict_types=1);

namespace Cronos\Media\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class InvalidImage extends DomainError
{
    public function __construct(string $reason = 'The image is corrupt or too large.')
    {
        parent::__construct($reason);
    }

    public function errorCode(): string
    {
        return 'INVALID_IMAGE';
    }
}
