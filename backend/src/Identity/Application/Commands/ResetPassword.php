<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Commands;

use Cronos\Shared\Domain\ClientContext;

final readonly class ResetPassword
{
    public function __construct(
        public string $email,
        public string $token,
        public string $newPassword,
        public ClientContext $client,
    ) {}
}
