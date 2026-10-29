<?php

declare(strict_types=1);

namespace Cronos\Pricing\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

/** A pricing rule or amount that makes no business sense (negative money, rate above 100%, mixed currencies...). */
final class InvalidPricing extends DomainError
{
    public function __construct(string $message, private readonly string $reason = 'INVALID_PRICING')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
