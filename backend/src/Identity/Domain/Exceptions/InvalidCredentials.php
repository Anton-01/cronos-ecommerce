<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class InvalidCredentials extends DomainError
{
    public function __construct()
    {
        parent::__construct('The provided credentials are invalid.');
    }

    public function errorCode(): string
    {
        return 'INVALID_CREDENTIALS';
    }
}
