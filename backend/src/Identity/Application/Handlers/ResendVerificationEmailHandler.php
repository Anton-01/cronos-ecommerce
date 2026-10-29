<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Handlers;

use Cronos\Identity\Application\Commands\ResendVerificationEmail;
use Cronos\Identity\Domain\Events\VerificationEmailRequested;
use Cronos\Identity\Domain\Ports\UserRepository;
use Cronos\Shared\Domain\DomainEventDispatcher;

final class ResendVerificationEmailHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly DomainEventDispatcher $events,
    ) {}

    public function __invoke(ResendVerificationEmail $command): void
    {
        $user = $this->users->findById($command->userId);

        if ($user !== null && ! $user->emailVerified) {
            $this->events->dispatch(new VerificationEmailRequested($user->id, $user->email));
        }
    }
}
