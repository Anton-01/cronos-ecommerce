<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Commands;

use Cronos\Shared\Domain\ClientContext;

final readonly class AssignRoles
{
    public function __construct(
        public string $userId,
        public array $roles,
        public ClientContext $client,
    ) {}
}
