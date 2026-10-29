<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\Ports;

use Cronos\Identity\Domain\ValueObjects\SocialProfile;

interface SocialIdentityProvider
{
    /** Exchanges a provider access token for a verified profile; null when rejected. */
    public function profileFromAccessToken(string $accessToken): ?SocialProfile;
}
