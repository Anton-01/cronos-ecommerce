<?php

declare(strict_types=1);

namespace Cronos\Markets\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class MarketError extends DomainError
{
    public function __construct(string $message, private readonly string $reason = 'MARKET_ERROR')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
