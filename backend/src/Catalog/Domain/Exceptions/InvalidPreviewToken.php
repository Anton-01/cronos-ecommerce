<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class InvalidPreviewToken extends DomainError
{
    public function __construct()
    {
        parent::__construct('The preview link is invalid or has expired.');
    }

    public function errorCode(): string
    {
        return 'INVALID_PREVIEW_TOKEN';
    }
}
