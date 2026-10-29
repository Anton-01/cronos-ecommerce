<?php

declare(strict_types=1);

namespace Cronos\Settings\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class InvalidSettingValue extends DomainError
{
    public function __construct(string $setting, string $reason)
    {
        parent::__construct("Invalid value for [{$setting}]: {$reason}");
    }

    public function errorCode(): string
    {
        return 'INVALID_SETTING';
    }
}
