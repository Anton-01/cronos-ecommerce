<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class CannotPublish extends DomainError
{
    public function __construct(array $problems)
    {
        parent::__construct('The product cannot be published: '.implode('; ', $problems));
    }

    public function errorCode(): string
    {
        return 'CANNOT_PUBLISH';
    }
}
