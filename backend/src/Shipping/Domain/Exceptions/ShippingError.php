<?php

declare(strict_types=1);

namespace Cronos\Shipping\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class ShippingError extends DomainError
{
    public function __construct(string $message, private readonly string $reason = 'SHIPPING_ERROR')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
