<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class NotFound extends DomainError
{
    public function __construct(string $what)
    {
        parent::__construct("{$what} does not exist.");
    }

    public function errorCode(): string
    {
        return 'NOT_FOUND';
    }
}
