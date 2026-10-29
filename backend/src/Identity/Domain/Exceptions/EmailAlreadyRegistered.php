<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class EmailAlreadyRegistered extends DomainError
{
    public function __construct()
    {
        parent::__construct('The email address is already registered.');
    }

    public function errorCode(): string
    {
        return 'EMAIL_TAKEN';
    }
}
