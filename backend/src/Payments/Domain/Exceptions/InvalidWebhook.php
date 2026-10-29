<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\Exceptions;

final class InvalidWebhook extends PaymentError
{
    public function __construct(string $message, string $reason = 'INVALID_WEBHOOK')
    {
        parent::__construct($message, $reason, false);
    }
}
