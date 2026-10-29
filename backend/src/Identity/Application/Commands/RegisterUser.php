<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Commands;

use Cronos\Shared\Domain\ClientContext;

final readonly class RegisterUser
{
    public function __construct(
        public string $email,
        public string $password,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $locale,
        public ClientContext $client,
    ) {}
}
