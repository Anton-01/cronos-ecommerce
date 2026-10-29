<?php

declare(strict_types=1);

namespace Cronos\Identity\Interfaces\GraphQL\Queries;

use Cronos\Identity\Infrastructure\Persistence\Eloquent\UserModel as Model;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Base query of the paginated list. Lighthouse does not apply SDL default values for
 *
 * @orderBy, so "newest first" is applied here when the client sends no explicit ordering.
 */
final class UserList
{
    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context, ResolveInfo $info): Builder
    {
        return Model::query()->when(empty($args['orderBy']), fn (Builder $q) => $q->orderByDesc('created_at'));
    }
}
