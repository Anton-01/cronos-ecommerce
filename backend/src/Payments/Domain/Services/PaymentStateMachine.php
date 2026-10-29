<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\Services;

use Cronos\Payments\Domain\ValueObjects\PaymentStatus as S;

/**
 * Which status changes a payment accepts. Providers deliver events late, twice and out of order, so the rules are:
 * the same status again is a no-op, a payment never goes back to an earlier stage, and money arriving after the shop gave up
 * on the payment is accepted (the money is real) but flagged for a person.
 */
final class PaymentStateMachine
{
    private const FORWARD = [
        S::PENDING => [S::REQUIRES_ACTION, S::PROCESSING, S::CAPTURED, S::FAILED, S::CANCELLED],
        S::REQUIRES_ACTION => [S::PROCESSING, S::CAPTURED, S::FAILED, S::CANCELLED],
        S::PROCESSING => [S::CAPTURED, S::FAILED, S::CANCELLED],
        S::CAPTURED => [S::PARTIALLY_REFUNDED, S::REFUNDED, S::DISPUTED],
        S::PARTIALLY_REFUNDED => [S::PARTIALLY_REFUNDED, S::REFUNDED, S::DISPUTED],
        S::DISPUTED => [S::CAPTURED, S::REFUNDED, S::PARTIALLY_REFUNDED],
        S::FAILED => [],
        S::CANCELLED => [],
        S::REFUNDED => [],
    ];

    /**
     * @return array{outcome: 'apply'|'noop'|'anomaly'|'reject', status: string, anomaly: ?string}
     */
    public function decide(string $current, string $target): array
    {
        if ($current === $target && $current !== S::PARTIALLY_REFUNDED) {
            return ['outcome' => 'noop', 'status' => $current, 'anomaly' => null];
        }
        if (in_array($target, self::FORWARD[$current] ?? [], true)) {
            return ['outcome' => 'apply', 'status' => $target, 'anomaly' => null];
        }
        // Money showed up for a payment we had already written off.
        if (in_array($current, [S::FAILED, S::CANCELLED], true) && $target === S::CAPTURED) {
            return ['outcome' => 'anomaly', 'status' => $target, 'anomaly' => 'late_capture'];
        }

        // Stale or contradictory (e.g. "processing" after "captured"): keep what we have.
        return ['outcome' => 'reject', 'status' => $current, 'anomaly' => null];
    }
}
