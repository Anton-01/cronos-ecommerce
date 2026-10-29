<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Handlers;

use Cronos\Identity\Application\AuthResult;
use Cronos\Identity\Application\Commands\AuthenticateWithGoogle;
use Cronos\Identity\Domain\Exceptions\AccountDisabled;
use Cronos\Identity\Domain\Exceptions\SocialLoginRejected;
use Cronos\Identity\Domain\Ports\SecurityEventRecorder;
use Cronos\Identity\Domain\Ports\SocialIdentityProvider;
use Cronos\Identity\Domain\Ports\TokenIssuer;
use Cronos\Identity\Domain\Ports\UserRepository;

final class AuthenticateWithGoogleHandler
{
    private const PROVIDER = 'google';

    public function __construct(
        private readonly SocialIdentityProvider $provider,
        private readonly UserRepository $users,
        private readonly TokenIssuer $tokens,
        private readonly SecurityEventRecorder $security,
    ) {}

    public function __invoke(AuthenticateWithGoogle $command): AuthResult
    {
        $profile = $this->provider->profileFromAccessToken($command->accessToken);

        // Never trust an unverified provider email: it could be used to take over an existing account.
        if ($profile === null || ! $profile->emailVerified) {
            $this->security->record('login.social_rejected', null, null, $command->client, ['provider' => self::PROVIDER]);
            throw new SocialLoginRejected;
        }

        $email = mb_strtolower($profile->email);
        $user = $this->users->findBySocialAccount(self::PROVIDER, $profile->providerUserId);

        if ($user === null) {
            $user = $this->users->findByEmail($email);

            if ($user === null) {
                $user = $this->users->create($email, null, [
                    'first_name' => $profile->firstName,
                    'last_name' => $profile->lastName,
                ], emailVerified: true);
                $this->users->assignDefaultRole($user->id);
                $this->security->record('user.registered', $user->id, $email, $command->client, ['provider' => self::PROVIDER]);
            } elseif (! $user->emailVerified) {
                $this->users->markEmailVerified($user->id);
            }

            $this->users->linkSocialAccount($user->id, $profile);
        }

        if (! $user->isActive) {
            throw new AccountDisabled;
        }

        $this->users->recordLogin($user->id, $command->client->ip);
        $this->security->record('login.succeeded', $user->id, $email, $command->client, ['provider' => self::PROVIDER]);

        return new AuthResult($user, $this->tokens->issue($user->id, 'google-login', ['storefront']));
    }
}
