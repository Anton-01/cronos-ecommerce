<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\ValueObjects;

use Cronos\Payments\Domain\Exceptions\PaymentError;

final class Provider
{
    public const STRIPE = 'stripe';

    public const MERCADOPAGO = 'mercadopago';

    public const PAYPAL = 'paypal';

    public const ALL = [self::STRIPE, self::MERCADOPAGO, self::PAYPAL];

    public const SANDBOX = 'sandbox';

    public const LIVE = 'live';

    public static function assertKnown(string $provider): void
    {
        in_array($provider, self::ALL, true) || throw new PaymentError("Unknown payment provider [{$provider}].", 'UNKNOWN_PROVIDER');
    }

    public static function assertMode(string $mode): void
    {
        in_array($mode, [self::SANDBOX, self::LIVE], true) || throw new PaymentError("Unknown mode [{$mode}].", 'UNKNOWN_MODE');
    }
}
