<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Commands;

use Cronos\Shared\Domain\ClientContext;

final readonly class LogoutUser
{
    public function __construct(
        public string $userId,
        public ClientContext $client,
    ) {}
}
