<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class AddressError extends DomainError
{
    /** @param array<string, string> $fields */
    public function __construct(string $message, private readonly string $reason = 'INVALID_ADDRESS', public readonly array $fields = [])
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
