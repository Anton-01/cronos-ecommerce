<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Handlers;

use Cronos\Identity\Application\Commands\AssignRoles;
use Cronos\Identity\Domain\Ports\SecurityEventRecorder;
use Cronos\Identity\Domain\Ports\UserRepository;

final class AssignRolesHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SecurityEventRecorder $security,
    ) {}

    public function __invoke(AssignRoles $command): void
    {
        $this->users->syncRoles($command->userId, $command->roles);
        $this->security->record('user.roles_changed', $command->userId, null, $command->client, ['roles' => $command->roles]);
    }
}
