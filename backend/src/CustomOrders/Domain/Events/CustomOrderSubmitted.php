<?php

declare(strict_types=1);

namespace Cronos\CustomOrders\Domain\Events;

final readonly class CustomOrderSubmitted
{
    public function __construct(public string $requestId, public string $number, public string $email, public string $title) {}
}
