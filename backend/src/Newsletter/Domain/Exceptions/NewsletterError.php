<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class NewsletterError extends DomainError
{
    /** @param array<string, string> $fields */
    public function __construct(string $message, private readonly string $reason = 'INVALID_EMAIL', public readonly array $fields = [])
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
