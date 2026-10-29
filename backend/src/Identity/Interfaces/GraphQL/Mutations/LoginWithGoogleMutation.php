<?php

declare(strict_types=1);

namespace Cronos\Identity\Interfaces\GraphQL\Mutations;

use Cronos\Identity\Application\Commands\AuthenticateWithGoogle;
use Cronos\Identity\Application\Handlers\AuthenticateWithGoogleHandler;
use Cronos\Identity\Interfaces\GraphQL\AuthPayloadFactory;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class LoginWithGoogleMutation
{
    use ResolvesClientContext;

    public function __construct(private readonly AuthenticateWithGoogleHandler $handler) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        return AuthPayloadFactory::from(($this->handler)(new AuthenticateWithGoogle(
            $args['accessToken'],
            $this->clientContext($context),
        )));
    }
}
