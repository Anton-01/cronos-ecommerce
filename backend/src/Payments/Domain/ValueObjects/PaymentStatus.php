<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\ValueObjects;

final class PaymentStatus
{
    public const PENDING = 'pending';

    public const REQUIRES_ACTION = 'requires_action';

    public const PROCESSING = 'processing';

    public const CAPTURED = 'captured';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const PARTIALLY_REFUNDED = 'partially_refunded';

    public const REFUNDED = 'refunded';

    public const DISPUTED = 'disputed';

    public const ALL = [self::PENDING, self::REQUIRES_ACTION, self::PROCESSING, self::CAPTURED, self::FAILED, self::CANCELLED, self::PARTIALLY_REFUNDED, self::REFUNDED, self::DISPUTED];

    /** Not finished: a provider may still change it. */
    public const OPEN = [self::PENDING, self::REQUIRES_ACTION, self::PROCESSING];

    /** Money was collected (and not all of it returned). */
    public const PAID = [self::CAPTURED, self::PARTIALLY_REFUNDED, self::DISPUTED];

    public static function isOpen(string $status): bool
    {
        return in_array($status, self::OPEN, true);
    }

    public static function isPaid(string $status): bool
    {
        return in_array($status, self::PAID, true);
    }
}
