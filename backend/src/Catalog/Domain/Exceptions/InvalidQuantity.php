<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class InvalidQuantity extends DomainError
{
    public const BELOW_MIN = 'QUANTITY_BELOW_MIN';

    public const ABOVE_MAX = 'QUANTITY_ABOVE_MAX';

    public const NOT_STEP_MULTIPLE = 'QUANTITY_NOT_STEP_MULTIPLE';

    public const MODE_NOT_ALLOWED = 'SALE_MODE_NOT_ALLOWED';

    public function __construct(private readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
