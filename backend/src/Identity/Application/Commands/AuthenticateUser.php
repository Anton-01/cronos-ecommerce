<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Commands;

use Cronos\Shared\Domain\ClientContext;

final readonly class AuthenticateUser
{
    public function __construct(
        public string $email,
        public string $password,
        public string $scope,
        public ClientContext $client,
    ) {}
}
