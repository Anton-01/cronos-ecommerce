<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class InsufficientStock extends DomainError
{
    public function __construct(public readonly string $requested, public readonly string $available, public readonly ?string $sku = null)
    {
        parent::__construct(sprintf('Not enough stock%s: %s requested, %s available.', $sku ? " of {$sku}" : '', $requested, $available));
    }

    public function errorCode(): string
    {
        return 'INSUFFICIENT_STOCK';
    }
}
