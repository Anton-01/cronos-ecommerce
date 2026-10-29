<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class InvalidOrExpiredToken extends DomainError
{
    public function __construct()
    {
        parent::__construct('The token is invalid or has expired.');
    }

    public function errorCode(): string
    {
        return 'INVALID_TOKEN';
    }
}
