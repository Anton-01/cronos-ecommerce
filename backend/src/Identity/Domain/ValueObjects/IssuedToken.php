<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\ValueObjects;

final readonly class IssuedToken
{
    public function __construct(
        public string $plainText,
        public ?\DateTimeImmutable $expiresAt,
    ) {}
}
