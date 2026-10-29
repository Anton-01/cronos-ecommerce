<?php

declare(strict_types=1);

namespace Cronos\Identity\Interfaces\GraphQL\Queries;

use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class Me
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): mixed
    {
        return $context->user();
    }
}
