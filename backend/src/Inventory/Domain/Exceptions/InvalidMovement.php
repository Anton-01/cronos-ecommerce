<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class InvalidMovement extends DomainError
{
    public function __construct(string $message, private readonly string $reason = 'INVALID_MOVEMENT')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
