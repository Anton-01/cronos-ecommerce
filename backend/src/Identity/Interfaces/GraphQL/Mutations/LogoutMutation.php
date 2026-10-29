<?php

declare(strict_types=1);

namespace Cronos\Identity\Interfaces\GraphQL\Mutations;

use Cronos\Identity\Application\Commands\LogoutUser;
use Cronos\Identity\Application\Handlers\LogoutUserHandler;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class LogoutMutation
{
    use ResolvesClientContext;

    public function __construct(private readonly LogoutUserHandler $handler) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): bool
    {
        ($this->handler)(new LogoutUser((string) $context->user()->getAuthIdentifier(), $this->clientContext($context)));

        return true;
    }
}
