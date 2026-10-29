<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\Events;

final readonly class PasswordResetRequested
{
    public function __construct(public string $email, public string $token) {}
}
