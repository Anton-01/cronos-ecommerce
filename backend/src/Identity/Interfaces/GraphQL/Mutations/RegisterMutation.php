<?php

declare(strict_types=1);

namespace Cronos\Identity\Interfaces\GraphQL\Mutations;

use Cronos\Identity\Application\Commands\RegisterUser;
use Cronos\Identity\Application\Handlers\RegisterUserHandler;
use Cronos\Identity\Interfaces\GraphQL\AuthPayloadFactory;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class RegisterMutation
{
    use ResolvesClientContext;

    public function __construct(private readonly RegisterUserHandler $handler) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $input = $args['input'];

        return AuthPayloadFactory::from(($this->handler)(new RegisterUser(
            $input['email'],
            $input['password'],
            $input['firstName'] ?? null,
            $input['lastName'] ?? null,
            $input['locale'] ?? null,
            $this->clientContext($context),
        )));
    }
}
