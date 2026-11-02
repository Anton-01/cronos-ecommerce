<?php

declare(strict_types=1);

namespace Cronos\Content\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class ContentError extends DomainError
{
    /** @param array<string, string> $fields */
    public function __construct(string $message, private readonly string $reason = 'CONTENT_ERROR', public readonly array $fields = [])
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
