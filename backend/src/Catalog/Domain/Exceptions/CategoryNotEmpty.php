<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class CategoryNotEmpty extends DomainError
{
    public function __construct()
    {
        parent::__construct('Move or delete the subcategories first.');
    }

    public function errorCode(): string
    {
        return 'CATEGORY_HAS_CHILDREN';
    }
}
