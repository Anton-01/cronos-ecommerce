<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class AccountDisabled extends DomainError
{
    public function __construct()
    {
        parent::__construct('This account is disabled.');
    }

    public function errorCode(): string
    {
        return 'ACCOUNT_DISABLED';
    }
}
