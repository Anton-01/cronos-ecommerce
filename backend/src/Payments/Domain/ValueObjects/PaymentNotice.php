<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\ValueObjects;

/**
 * A provider-neutral statement about one payment: "this is its status now", normalised from a webhook or a lookup.
 * Applying the same notice twice must change nothing.
 */
final readonly class PaymentNotice
{
    public const KIND_STATUS = 'status';

    public const KIND_REFUND = 'refund';

    public const KIND_DISPUTE = 'dispute';

    public const KIND_IGNORE = 'ignore';

    /** @param array<string, mixed> $raw */
    public function __construct(
        public string $kind,
        public ?string $paymentId, // our id, recovered from the metadata we sent
        public ?string $providerReference,
        public ?string $status = null, // PaymentStatus value for KIND_STATUS
        public ?string $amount = null, // collected amount for captured, refund amount for refunds
        public ?string $currency = null,
        public ?string $eventId = null,
        public ?string $refundReference = null,
        public ?string $refundTotal = null, // cumulative refunded amount as the provider sees it, when known
        public bool $disputeWon = false,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public bool $needsCapture = false, // PayPal: approved, now we must capture
        public array $raw = [],
    ) {}

    public static function ignore(string $why, ?string $eventId = null): self
    {
        return new self(self::KIND_IGNORE, null, null, eventId: $eventId, failureMessage: $why);
    }
}
