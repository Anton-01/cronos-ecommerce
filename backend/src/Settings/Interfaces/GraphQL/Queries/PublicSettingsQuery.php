<?php

declare(strict_types=1);

namespace Cronos\Settings\Interfaces\GraphQL\Queries;

use Cronos\Settings\Application\PublicSettingsReader;

final class PublicSettingsQuery
{
    public function __construct(private readonly PublicSettingsReader $reader) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args): array
    {
        return $this->reader->read($args['locale'] ?? null);
    }
}
