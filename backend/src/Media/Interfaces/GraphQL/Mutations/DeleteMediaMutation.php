<?php

declare(strict_types=1);

namespace Cronos\Media\Interfaces\GraphQL\Mutations;

use Cronos\Media\Application\Commands\DeleteMedia;
use Cronos\Media\Application\Handlers\DeleteMediaHandler;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class DeleteMediaMutation
{
    public function __construct(private readonly DeleteMediaHandler $handler) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): bool
    {
        ($this->handler)(new DeleteMedia($args['id']));

        return true;
    }
}
