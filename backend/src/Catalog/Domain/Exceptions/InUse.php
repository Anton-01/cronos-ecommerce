<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class InUse extends DomainError
{
    public function __construct(string $what)
    {
        parent::__construct("{$what} is in use and cannot be deleted.");
    }

    public function errorCode(): string
    {
        return 'IN_USE';
    }
}
