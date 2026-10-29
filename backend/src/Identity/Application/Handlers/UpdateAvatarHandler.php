<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Handlers;

use Cronos\Identity\Application\Commands\UpdateAvatar;
use Cronos\Identity\Domain\Ports\AvatarStore;

final class UpdateAvatarHandler
{
    public function __construct(private readonly AvatarStore $avatars) {}

    public function __invoke(UpdateAvatar $command): void
    {
        $this->avatars->replace($command->userId, $command->path, $command->clientName);
    }
}
