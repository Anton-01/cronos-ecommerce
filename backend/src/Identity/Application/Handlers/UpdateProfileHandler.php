<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Handlers;

use Cronos\Identity\Application\Commands\UpdateProfile;
use Cronos\Identity\Domain\Ports\UserRepository;

final class UpdateProfileHandler
{
    public function __construct(private readonly UserRepository $users) {}

    public function __invoke(UpdateProfile $command): void
    {
        $this->users->updateProfile($command->userId, $command->attributes);
    }
}
