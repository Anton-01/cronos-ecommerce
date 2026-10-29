<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class SlugTaken extends DomainError
{
    public function __construct(string $slug, string $locale)
    {
        parent::__construct("The slug [{$slug}] is already used in {$locale}.");
    }

    public function errorCode(): string
    {
        return 'SLUG_TAKEN';
    }
}
