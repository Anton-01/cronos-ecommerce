<?php

declare(strict_types=1);

namespace Cronos\Shared\Domain;

/** Network metadata of the caller, captured at the edge and passed inward. */
final readonly class ClientContext
{
    public function __construct(
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {}
}
