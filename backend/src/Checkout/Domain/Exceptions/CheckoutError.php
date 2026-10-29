<?php

declare(strict_types=1);

namespace Cronos\Checkout\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

/** A cart or checkout operation that cannot proceed; the code tells the storefront what to show. */
final class CheckoutError extends DomainError
{
    public function __construct(string $message, private readonly string $reason = 'CHECKOUT_ERROR')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
