<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Handlers;

use Cronos\Identity\Application\Commands\LogoutUser;
use Cronos\Identity\Domain\Ports\SecurityEventRecorder;
use Cronos\Identity\Domain\Ports\TokenIssuer;

final class LogoutUserHandler
{
    public function __construct(
        private readonly TokenIssuer $tokens,
        private readonly SecurityEventRecorder $security,
    ) {}

    public function __invoke(LogoutUser $command): void
    {
        $this->tokens->revokeCurrent($command->userId);
        $this->security->record('logout', $command->userId, null, $command->client);
    }
}
