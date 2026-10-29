<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\Ports;

use Cronos\Identity\Domain\ValueObjects\IssuedToken;

interface TokenIssuer
{
    /** @param list<string> $abilities */
    public function issue(string $userId, string $deviceName, array $abilities): IssuedToken;

    /** Revokes the token the caller is currently authenticated with. */
    public function revokeCurrent(string $userId): void;

    public function revokeAll(string $userId): void;
}
