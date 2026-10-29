<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Handlers;

use Cronos\Identity\Application\Commands\ResetPassword;
use Cronos\Identity\Domain\Exceptions\InvalidOrExpiredToken;
use Cronos\Identity\Domain\Ports\PasswordHasher;
use Cronos\Identity\Domain\Ports\PasswordResetTokenStore;
use Cronos\Identity\Domain\Ports\SecurityEventRecorder;
use Cronos\Identity\Domain\Ports\TokenIssuer;
use Cronos\Identity\Domain\Ports\UserRepository;

final class ResetPasswordHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetTokenStore $store,
        private readonly PasswordHasher $hasher,
        private readonly TokenIssuer $tokens,
        private readonly SecurityEventRecorder $security,
    ) {}

    public function __invoke(ResetPassword $command): void
    {
        $email = mb_strtolower(trim($command->email));
        $user = $this->users->findByEmail($email);

        if ($user === null || ! $this->store->consume($email, $command->token)) {
            throw new InvalidOrExpiredToken;
        }

        $this->users->updatePasswordHash($user->id, $this->hasher->hash($command->newPassword));
        // Receiving the reset link proves ownership of the mailbox.
        $this->users->markEmailVerified($user->id);
        // Invalidate every session: a reset implies the old credentials may be compromised.
        $this->tokens->revokeAll($user->id);

        $this->security->record('password.reset', $user->id, $email, $command->client);
    }
}
