<?php

declare(strict_types=1);

namespace Cronos\Contact\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class ContactError extends DomainError
{
    /** @param array<string, string> $fields */
    public function __construct(string $message, private readonly string $reason = 'INVALID_MESSAGE', public readonly array $fields = [])
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
