<?php

declare(strict_types=1);

namespace Cronos\Identity\Interfaces\GraphQL\Mutations;

use Cronos\Identity\Application\Commands\RequestPasswordReset;
use Cronos\Identity\Application\Handlers\RequestPasswordResetHandler;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class ForgotPasswordMutation
{
    use ResolvesClientContext;

    public function __construct(private readonly RequestPasswordResetHandler $handler) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): bool
    {
        ($this->handler)(new RequestPasswordReset($args['email'], $this->clientContext($context)));

        // Always true: the response must not reveal whether the email is registered.
        return true;
    }
}
