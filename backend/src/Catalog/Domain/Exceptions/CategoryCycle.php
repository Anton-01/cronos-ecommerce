<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class CategoryCycle extends DomainError
{
    public function __construct()
    {
        parent::__construct('A category cannot be moved under itself or one of its descendants.');
    }

    public function errorCode(): string
    {
        return 'CATEGORY_CYCLE';
    }
}
