<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Handlers;

use Cronos\Identity\Application\Commands\VerifyEmail;
use Cronos\Identity\Domain\Exceptions\InvalidOrExpiredToken;
use Cronos\Identity\Domain\Ports\EmailVerificationLinks;
use Cronos\Identity\Domain\Ports\UserRepository;

final class VerifyEmailHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EmailVerificationLinks $links,
    ) {}

    public function __invoke(VerifyEmail $command): void
    {
        $user = $this->users->findById($command->userId);

        if ($user === null || ! $this->links->isValid($user->id, $user->email, $command->expires, $command->signature)) {
            throw new InvalidOrExpiredToken;
        }

        if (! $user->emailVerified) {
            $this->users->markEmailVerified($user->id);
        }
    }
}
