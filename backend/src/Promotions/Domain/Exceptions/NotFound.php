<?php

declare(strict_types=1);

namespace Cronos\Promotions\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class NotFound extends DomainError
{
    public function __construct(string $what)
    {
        parent::__construct("{$what} was not found.");
    }

    public function errorCode(): string
    {
        return 'NOT_FOUND';
    }
}
