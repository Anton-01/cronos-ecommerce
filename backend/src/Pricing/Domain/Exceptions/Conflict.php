<?php

declare(strict_types=1);

namespace Cronos\Pricing\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

/** Uniqueness or in-use violations (duplicate code, deleting the default currency...). */
final class Conflict extends DomainError
{
    public function __construct(string $message, private readonly string $reason = 'CONFLICT')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
