<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\Exceptions;

final class NotFound extends PaymentError
{
    public function __construct(string $what)
    {
        parent::__construct("{$what} was not found.", 'NOT_FOUND');
    }
}
