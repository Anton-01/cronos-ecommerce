<?php

declare(strict_types=1);

namespace Cronos\Identity\Application\Commands;

final readonly class UpdateProfile
{
    public function __construct(
        public string $userId,
        public array $attributes,
    ) {}
}
