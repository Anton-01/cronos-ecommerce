<?php

declare(strict_types=1);

namespace Cronos\Identity\Interfaces\GraphQL\Mutations;

use Cronos\Identity\Application\Commands\AssignRoles;
use Cronos\Identity\Application\Handlers\AssignRolesHandler;
use Cronos\Identity\Infrastructure\Persistence\Eloquent\UserModel;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use GraphQL\Error\UserError;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Spatie\Permission\Models\Role;

final class AssignUserRolesMutation
{
    use ResolvesClientContext;

    public function __construct(private readonly AssignRolesHandler $handler) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): UserModel
    {
        $known = Role::query()->whereIn('name', $args['roles'])->pluck('name')->all();

        if (count($known) !== count(array_unique($args['roles']))) {
            throw new UserError('One or more roles do not exist.');
        }

        ($this->handler)(new AssignRoles($args['id'], array_values($args['roles']), $this->clientContext($context)));

        return UserModel::query()->findOrFail($args['id']);
    }
}
