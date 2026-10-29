<?php

declare(strict_types=1);

namespace Cronos\Orders\Domain\Services;

use Cronos\Orders\Domain\Exceptions\OrderError;

/** Which logistic moves an order may make. Money moves (payment_status) are driven by the payment events, not by this table. */
final class OrderStateMachine
{
    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const SHIPPED = 'shipped';

    public const DELIVERED = 'delivered';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const REFUNDED = 'refunded';

    private const MOVES = [
        self::PENDING => [self::PROCESSING, self::CANCELLED],
        self::PROCESSING => [self::SHIPPED, self::CANCELLED, self::REFUNDED],
        self::SHIPPED => [self::DELIVERED],
        self::DELIVERED => [self::COMPLETED, self::REFUNDED],
        self::COMPLETED => [self::REFUNDED],
        self::CANCELLED => [],
        self::REFUNDED => [],
    ];

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::MOVES[$from] ?? [], true);
    }

    public static function assert(string $from, string $to): void
    {
        self::can($from, $to) || throw new OrderError("An order that is {$from} cannot become {$to}.", 'INVALID_TRANSITION');
    }

    /** @return list<string> */
    public static function next(string $from): array
    {
        return self::MOVES[$from] ?? [];
    }
}
