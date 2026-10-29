<?php

declare(strict_types=1);

namespace Cronos\Fraud\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class InvalidFraudRule extends DomainError
{
    public function errorCode(): string
    {
        return 'INVALID_FRAUD_RULE';
    }
}
