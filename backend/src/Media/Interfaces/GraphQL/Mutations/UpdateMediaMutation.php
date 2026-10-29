<?php

declare(strict_types=1);

namespace Cronos\Media\Interfaces\GraphQL\Mutations;

use Cronos\Media\Application\Commands\RenameMedia;
use Cronos\Media\Application\Commands\UpdateMediaTranslations;
use Cronos\Media\Application\Handlers\RenameMediaHandler;
use Cronos\Media\Application\Handlers\UpdateMediaTranslationsHandler;
use Cronos\Media\Infrastructure\Persistence\Eloquent\MediaModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class UpdateMediaMutation
{
    public function __construct(private readonly RenameMediaHandler $rename, private readonly UpdateMediaTranslationsHandler $translations) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): MediaModel
    {
        if (isset($args['name'])) {
            ($this->rename)(new RenameMedia($args['id'], $args['name']));
        }

        if (! empty($args['translations'])) {
            $byLocale = [];
            foreach ($args['translations'] as $t) {
                $byLocale[$t['locale']] = ['alt' => $t['alt'] ?? null, 'title' => $t['title'] ?? null, 'caption' => $t['caption'] ?? null];
            }
            ($this->translations)(new UpdateMediaTranslations($args['id'], $byLocale));
        }

        return MediaModel::query()->findOrFail($args['id']);
    }
}
