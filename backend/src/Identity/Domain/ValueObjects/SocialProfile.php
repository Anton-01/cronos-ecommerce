<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\ValueObjects;

final readonly class SocialProfile
{
    public function __construct(
        public string $provider,
        public string $providerUserId,
        public string $email,
        public bool $emailVerified,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $avatarUrl,
    ) {}
}
