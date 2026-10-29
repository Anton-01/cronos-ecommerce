<?php

declare(strict_types=1);

namespace Cronos\Orders\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class OrderError extends DomainError
{
    public function __construct(string $message, private readonly string $reason = 'ORDER_ERROR')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
