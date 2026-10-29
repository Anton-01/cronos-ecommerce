<?php

declare(strict_types=1);

namespace Cronos\CustomOrders\Domain\Events;

/** Staff sent a quote to the customer. */
final readonly class QuoteSent
{
    public function __construct(public string $requestId, public string $quoteId, public string $number, public string $email, public ?string $userId, public string $marketCode, public string $total, public string $currency, public ?string $validUntil) {}
}
