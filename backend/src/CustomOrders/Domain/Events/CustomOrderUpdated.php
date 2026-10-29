<?php

declare(strict_types=1);

namespace Cronos\CustomOrders\Domain\Events;

/** Something the customer should hear about: a staff reply, a rejection. `kind` is message|rejected. */
final readonly class CustomOrderUpdated
{
    public function __construct(public string $requestId, public string $number, public string $email, public ?string $userId, public string $marketCode, public string $kind, public string $message, public string $dedupeKey) {}
}
