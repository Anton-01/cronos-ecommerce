<?php

declare(strict_types=1);

namespace Cronos\Identity\Interfaces\GraphQL\Mutations;

use Cronos\Identity\Application\Commands\AuthenticateUser;
use Cronos\Identity\Application\Handlers\AuthenticateUserHandler;
use Cronos\Identity\Interfaces\GraphQL\AuthPayloadFactory;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class AdminLoginMutation
{
    use ResolvesClientContext;

    public function __construct(private readonly AuthenticateUserHandler $handler) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $input = $args['input'];

        return AuthPayloadFactory::from(($this->handler)(new AuthenticateUser(
            $input['email'],
            $input['password'],
            AuthenticateUserHandler::SCOPE_ADMIN,
            $this->clientContext($context),
        )));
    }
}
