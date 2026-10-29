<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

/** A payment operation that did not succeed. `retryable` tells callers (jobs, shoppers) whether trying again can help. */
class PaymentError extends DomainError
{
    public function __construct(string $message, private readonly string $reason = 'PAYMENT_ERROR', public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
