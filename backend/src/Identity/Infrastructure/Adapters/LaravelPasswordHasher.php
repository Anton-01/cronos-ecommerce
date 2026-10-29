<?php

declare(strict_types=1);

namespace Cronos\Identity\Infrastructure\Adapters;

use Cronos\Identity\Domain\Ports\PasswordHasher;
use Illuminate\Contracts\Hashing\Hasher;

final class LaravelPasswordHasher implements PasswordHasher
{
    public function __construct(private readonly Hasher $hasher) {}

    public function hash(string $plain): string
    {
        return $this->hasher->make($plain);
    }

    public function verify(string $plain, string $hash): bool
    {
        return $this->hasher->check($plain, $hash);
    }
}
