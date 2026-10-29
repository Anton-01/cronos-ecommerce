<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class InvalidProduct extends DomainError
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }

    public function errorCode(): string
    {
        return 'INVALID_PRODUCT';
    }
}
