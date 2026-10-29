<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Handlers;

use Cronos\Identity\Application\Commands\RequestPasswordReset;
use Cronos\Identity\Domain\Events\PasswordResetRequested;
use Cronos\Identity\Domain\Ports\DomainEventDispatcher;
use Cronos\Identity\Domain\Ports\PasswordResetTokenStore;
use Cronos\Identity\Domain\Ports\SecurityEventRecorder;
use Cronos\Identity\Domain\Ports\UserRepository;

final class RequestPasswordResetHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetTokenStore $store,
        private readonly DomainEventDispatcher $events,
        private readonly SecurityEventRecorder $security,
    ) {}

    /** Silent when the account does not exist, to prevent email enumeration. */
    public function __invoke(RequestPasswordReset $command): void
    {
        $email = mb_strtolower(trim($command->email));
        $user = $this->users->findByEmail($email);

        $this->security->record('password.reset_requested', $user?->id, $email, $command->client);

        if ($user === null || ! $user->isActive) {
            return;
        }

        $this->events->dispatch(new PasswordResetRequested($email, $this->store->create($email)));
    }
}
