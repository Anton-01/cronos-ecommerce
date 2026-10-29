<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class SkuTaken extends DomainError
{
    public function __construct(string $sku)
    {
        parent::__construct("The SKU [{$sku}] already exists.");
    }

    public function errorCode(): string
    {
        return 'SKU_TAKEN';
    }
}
