<?php

declare(strict_types=1);

namespace Cronos\Identity\Infrastructure\Adapters;

use Cronos\Identity\Domain\Ports\SocialIdentityProvider;
use Cronos\Identity\Domain\ValueObjects\SocialProfile;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;

/** SPA flow: the client obtains a Google access token and we only validate it server-side. */
final class SocialiteGoogleProvider implements SocialIdentityProvider
{
    public function profileFromAccessToken(string $accessToken): ?SocialProfile
    {
        try {
            /** @var GoogleProvider $driver */
            $driver = Socialite::driver('google');
            $user = $driver->stateless()->userFromToken($accessToken);
        } catch (\Throwable) {
            return null;
        }

        $raw = $user->getRaw();

        if (! $user->getEmail()) {
            return null;
        }

        return new SocialProfile(
            provider: 'google',
            providerUserId: (string) $user->getId(),
            email: (string) $user->getEmail(),
            emailVerified: (bool) ($raw['email_verified'] ?? $raw['verified_email'] ?? false),
            firstName: $raw['given_name'] ?? null,
            lastName: $raw['family_name'] ?? null,
            avatarUrl: $user->getAvatar(),
        );
    }
}
