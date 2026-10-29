<?php

declare(strict_types=1);

namespace Cronos\CustomOrders\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class CustomOrderError extends DomainError
{
    public function __construct(string $message, private readonly string $reason = 'CUSTOM_ORDER_ERROR')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
