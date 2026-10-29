<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\Ports;

use Cronos\Identity\Domain\ValueObjects\SocialProfile;
use Cronos\Identity\Domain\ValueObjects\UserRecord;

interface UserRepository
{
    public function findById(string $id): ?UserRecord;

    public function findByEmail(string $email): ?UserRecord;

    public function findBySocialAccount(string $provider, string $providerUserId): ?UserRecord;

    /** @param array{first_name?: ?string, last_name?: ?string, locale?: ?string, country_code?: ?string} $profile */
    public function create(string $email, ?string $passwordHash, array $profile, bool $emailVerified = false): UserRecord;

    public function linkSocialAccount(string $userId, SocialProfile $profile): void;

    public function markEmailVerified(string $userId): void;

    public function passwordHashOf(string $userId): ?string;

    public function updatePasswordHash(string $userId, string $hash): void;

    public function recordLogin(string $userId, ?string $ip): void;

    /** @param array<string, mixed> $attributes Whitelisted profile columns. */
    public function updateProfile(string $userId, array $attributes): void;

    public function setActive(string $userId, bool $active): void;

    /** @param list<string> $roles */
    public function syncRoles(string $userId, array $roles): void;

    /** True when the account holds any permission that unlocks the admin panel. */
    public function canAccessBackoffice(string $userId): bool;

    /** Grants the default role to a freshly created account. */
    public function assignDefaultRole(string $userId): void;
}
