<?php

declare(strict_types=1);

namespace Cronos\Media\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class UnknownLocale extends DomainError
{
    public function __construct(string $locale)
    {
        parent::__construct("Locale [{$locale}] is not supported.");
    }

    public function errorCode(): string
    {
        return 'UNKNOWN_LOCALE';
    }
}
