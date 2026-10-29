<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Handlers;

use Cronos\Identity\Application\AuthResult;
use Cronos\Identity\Application\Commands\AuthenticateUser;
use Cronos\Identity\Domain\Exceptions\AccountDisabled;
use Cronos\Identity\Domain\Exceptions\InvalidCredentials;
use Cronos\Identity\Domain\Ports\PasswordHasher;
use Cronos\Identity\Domain\Ports\SecurityEventRecorder;
use Cronos\Identity\Domain\Ports\TokenIssuer;
use Cronos\Identity\Domain\Ports\UserRepository;

final class AuthenticateUserHandler
{
    public const SCOPE_STOREFRONT = 'storefront';

    public const SCOPE_ADMIN = 'admin';

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly TokenIssuer $tokens,
        private readonly SecurityEventRecorder $security,
    ) {}

    public function __invoke(AuthenticateUser $command): AuthResult
    {
        $email = mb_strtolower(trim($command->email));
        $user = $this->users->findByEmail($email);
        $hash = $user !== null ? $this->users->passwordHashOf($user->id) : null;

        // Always run a hash comparison so response time does not reveal whether the email exists.
        $valid = $this->hasher->verify($command->password, $hash ?? $this->hasher->hash('timing-equalizer'));

        if ($user === null || $hash === null || ! $valid) {
            $this->security->record('login.failed', $user?->id, $email, $command->client);
            throw new InvalidCredentials;
        }

        if (! $user->isActive) {
            $this->security->record('login.blocked', $user->id, $email, $command->client);
            throw new AccountDisabled;
        }

        if ($command->scope === self::SCOPE_ADMIN && ! $this->users->canAccessBackoffice($user->id)) {
            // Same error as bad credentials: do not reveal that the account exists.
            $this->security->record('login.admin_denied', $user->id, $email, $command->client);
            throw new InvalidCredentials;
        }

        $this->users->recordLogin($user->id, $command->client->ip);
        $this->security->record('login.succeeded', $user->id, $email, $command->client, ['scope' => $command->scope]);

        return new AuthResult($user, $this->tokens->issue($user->id, $command->scope.'-login', [$command->scope]));
    }
}
