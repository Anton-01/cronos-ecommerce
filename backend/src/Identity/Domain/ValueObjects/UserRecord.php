<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\ValueObjects;

/** Read model of a user as the application layer sees it (no framework types). */
final readonly class UserRecord
{
    public function __construct(
        public string $id,
        public string $email,
        public bool $isActive,
        public bool $emailVerified,
        public bool $hasPassword,
    ) {}
}
