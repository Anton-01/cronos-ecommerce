<?php

declare(strict_types=1);

namespace Cronos\Shared\Domain;

/** Network metadata of the caller, captured at the edge and passed inward. Country and timezone come from the IP. */
final readonly class ClientContext
{
    public function __construct(
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $country = null,
        public ?string $timezone = null,
    ) {}
}
