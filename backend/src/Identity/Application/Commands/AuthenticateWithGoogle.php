<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Commands;

use Cronos\Shared\Domain\ClientContext;

final readonly class AuthenticateWithGoogle
{
    public function __construct(
        public string $accessToken,
        public ClientContext $client,
    ) {}
}
