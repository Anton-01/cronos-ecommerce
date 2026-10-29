<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

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
