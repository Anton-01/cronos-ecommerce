<?php

declare(strict_types=1);

namespace Cronos\Payments\Domain\ValueObjects;

/** What a gateway answered when asked to start (or look up) a payment. */
final readonly class GatewayPayment
{
    /** @param array<string, mixed>|null $clientAction what the shopper's device needs next: {type: client_secret|redirect, ...} @param array<string, mixed> $raw */
    public function __construct(
        public string $reference,
        public string $status,
        public ?array $clientAction = null,
        public array $raw = [],
    ) {}
}
