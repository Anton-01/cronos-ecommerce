<?php

declare(strict_types=1);

namespace Cronos\Media\Interfaces\GraphQL\Mutations;

use Cronos\Media\Application\Handlers\SetFocalPointHandler;
use Cronos\Media\Infrastructure\Persistence\Eloquent\MediaModel;

final class SetFocalPointMutation
{
    public function __construct(private readonly SetFocalPointHandler $handler) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args): MediaModel
    {
        ($this->handler)($args['id'], (float) $args['x'], (float) $args['y']);

        return MediaModel::query()->findOrFail($args['id']);
    }
}
