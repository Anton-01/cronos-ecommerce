<?php

declare(strict_types=1);

namespace Cronos\Identity\Application;

use Cronos\Identity\Domain\ValueObjects\IssuedToken;
use Cronos\Identity\Domain\ValueObjects\UserRecord;

final readonly class AuthResult
{
    public function __construct(public UserRecord $user, public IssuedToken $token) {}
}
