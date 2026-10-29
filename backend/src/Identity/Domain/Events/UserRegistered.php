<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\Events;

final readonly class UserRegistered
{
    public function __construct(public string $userId, public string $email) {}
}
