<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\Ports;

use Cronos\Shared\Domain\ClientContext;

interface SecurityEventRecorder
{
    /** @param array<string, mixed> $context */
    public function record(string $event, ?string $userId, ?string $email, ClientContext $client, array $context = []): void;
}
