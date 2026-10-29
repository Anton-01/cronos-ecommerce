<?php

declare(strict_types=1);

namespace Cronos\Shared\Interfaces\GraphQL;

use Cronos\Shared\Infrastructure\Geo\CurrentClient;

final class ClientContextQuery
{
    public function __construct(private readonly CurrentClient $client) {}

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        $l = $this->client->location();

        return ['ip' => $l->ip, 'country' => $l->country, 'timezone' => $l->timezone, 'utcOffset' => $l->offsetAt(new \DateTimeImmutable), 'source' => $l->source];
    }
}
