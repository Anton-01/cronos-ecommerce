<?php

declare(strict_types=1);

namespace Cronos\Appearance\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class ThemeError extends DomainError
{
    /** @param array<string, string> $fields path => problem, shown next to the field that caused it */
    public function __construct(string $message, private readonly string $reason = 'THEME_ERROR', public readonly array $fields = [])
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
