<?php

declare(strict_types=1);

namespace Cronos\Settings\Interfaces\GraphQL\Mutations;

use Cronos\Settings\Application\Commands\UpdateSettings;
use Cronos\Settings\Application\Handlers\UpdateSettingsHandler;
use Cronos\Settings\Interfaces\GraphQL\Queries\AdminSettingsQuery;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class UpdateSettingsMutation
{
    public function __construct(
        private readonly UpdateSettingsHandler $handler,
        private readonly AdminSettingsQuery $query,
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $changes = array_map(fn (array $c) => [
            'group' => $c['group'],
            'key' => $c['key'],
            'value' => $c['value'] ?? null,
            'translations' => collect($c['translations'] ?? [])->mapWithKeys(fn ($t) => [$t['locale'] => $t['value'] ?? null])->all(),
        ], $args['input']);

        ($this->handler)(new UpdateSettings($changes, (string) $context->user()->getAuthIdentifier()));

        return ($this->query)();
    }
}
