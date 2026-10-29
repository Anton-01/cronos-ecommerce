<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\Ports;

interface AvatarStore
{
    /** Stores the uploaded image as the user's only avatar, replacing any previous one. */
    public function replace(string $userId, string $uploadedPath, string $clientName): void;
}
