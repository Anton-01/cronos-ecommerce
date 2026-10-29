<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class CategoryTooDeep extends DomainError
{
    public function __construct(int $max)
    {
        parent::__construct("Categories can be nested at most {$max} levels.");
    }

    public function errorCode(): string
    {
        return 'CATEGORY_TOO_DEEP';
    }
}
