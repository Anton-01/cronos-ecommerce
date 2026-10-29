<?php

declare(strict_types=1);

namespace Cronos\Identity\Interfaces\GraphQL\Mutations;

use Cronos\Identity\Application\Commands\SetUserActive;
use Cronos\Identity\Application\Handlers\SetUserActiveHandler;
use Cronos\Identity\Infrastructure\Persistence\Eloquent\UserModel;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use GraphQL\Error\UserError;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class SetUserActiveMutation
{
    use ResolvesClientContext;

    public function __construct(private readonly SetUserActiveHandler $handler) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): UserModel
    {
        // Staff must not be able to lock themselves out by accident.
        if ($args['id'] === (string) $context->user()->getAuthIdentifier() && $args['active'] === false) {
            throw new UserError('You cannot disable your own account.');
        }

        ($this->handler)(new SetUserActive($args['id'], $args['active'], $this->clientContext($context)));

        return UserModel::query()->findOrFail($args['id']);
    }
}
