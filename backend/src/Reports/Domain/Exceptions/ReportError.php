<?php

declare(strict_types=1);

namespace Cronos\Reports\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class ReportError extends DomainError
{
    public function __construct(string $message, private readonly string $reason = 'REPORT_ERROR')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
