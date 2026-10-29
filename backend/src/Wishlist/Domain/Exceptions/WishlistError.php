<?php

declare(strict_types=1);

namespace Cronos\Wishlist\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class WishlistError extends DomainError
{
    public function __construct(string $message, private readonly string $reason = 'WISHLIST_ERROR')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
