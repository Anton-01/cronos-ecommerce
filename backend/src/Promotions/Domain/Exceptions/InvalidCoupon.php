<?php

declare(strict_types=1);

namespace Cronos\Promotions\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

/** The shopper-facing reason a coupon cannot be used right now; the code lets the storefront word it. */
final class InvalidCoupon extends DomainError
{
    public const NOT_FOUND = 'COUPON_NOT_FOUND';

    public const INACTIVE = 'COUPON_INACTIVE';

    public const NOT_STARTED = 'COUPON_NOT_STARTED';

    public const EXPIRED = 'COUPON_EXPIRED';

    public const EXHAUSTED = 'COUPON_EXHAUSTED';

    public const USER_LIMIT = 'COUPON_USER_LIMIT';

    public const MIN_SPEND = 'COUPON_MIN_SPEND';

    public const CURRENCY = 'COUPON_CURRENCY_MISMATCH';

    public const NOT_APPLICABLE = 'COUPON_NOT_APPLICABLE';

    public const FIRST_ORDER = 'COUPON_FIRST_ORDER_ONLY';

    public const MARKET = 'COUPON_MARKET';

    public function __construct(private readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
