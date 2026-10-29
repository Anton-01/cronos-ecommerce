<?php

declare(strict_types=1);

namespace Cronos\Settings\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class UnknownSetting extends DomainError
{
    public function __construct(string $group, string $key)
    {
        parent::__construct("Setting [{$group}.{$key}] does not exist.");
    }

    public function errorCode(): string
    {
        return 'UNKNOWN_SETTING';
    }
}
