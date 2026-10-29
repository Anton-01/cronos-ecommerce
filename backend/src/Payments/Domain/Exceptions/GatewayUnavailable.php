<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\Exceptions;

/** The provider timed out, answered 5xx/429, or its circuit is open. Nothing is known to have happened: safe to retry with the same idempotency key. */
final class GatewayUnavailable extends PaymentError
{
    public function __construct(string $provider, string $detail = '')
    {
        parent::__construct("The payment provider {$provider} is not answering right now.".($detail !== '' ? " ({$detail})" : ''), 'GATEWAY_UNAVAILABLE', true);
    }
}
