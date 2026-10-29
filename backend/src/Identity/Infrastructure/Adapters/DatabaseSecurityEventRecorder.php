<?php

declare(strict_types=1);

namespace Cronos\Identity\Infrastructure\Adapters;

use Cronos\Identity\Domain\Ports\SecurityEventRecorder;
use Cronos\Shared\Domain\ClientContext;
use Illuminate\Support\Facades\DB;

final class DatabaseSecurityEventRecorder implements SecurityEventRecorder
{
    public function record(string $event, ?string $userId, ?string $email, ClientContext $client, array $context = []): void
    {
        DB::table('security_events')->insert([
            'user_id' => $userId,
            'event' => $event,
            'email' => $email,
            'ip_address' => $client->ip,
            'country_code' => $client->country,
            'timezone' => $client->timezone,
            'user_agent' => $client->userAgent !== null ? mb_substr($client->userAgent, 0, 1023) : null,
            'context' => $context === [] ? null : json_encode($context, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
