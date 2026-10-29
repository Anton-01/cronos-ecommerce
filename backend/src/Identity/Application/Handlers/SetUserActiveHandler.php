<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Handlers;

use Cronos\Identity\Application\Commands\SetUserActive;
use Cronos\Identity\Domain\Ports\SecurityEventRecorder;
use Cronos\Identity\Domain\Ports\TokenIssuer;
use Cronos\Identity\Domain\Ports\UserRepository;

final class SetUserActiveHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TokenIssuer $tokens,
        private readonly SecurityEventRecorder $security,
    ) {}

    public function __invoke(SetUserActive $command): void
    {
        $this->users->setActive($command->userId, $command->active);

        if (! $command->active) {
            // A disabled account must lose every live session immediately.
            $this->tokens->revokeAll($command->userId);
        }

        $this->security->record($command->active ? 'user.enabled' : 'user.disabled', $command->userId, null, $command->client);
    }
}
