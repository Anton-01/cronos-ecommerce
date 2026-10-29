<?php

declare(strict_types=1);

namespace Cronos\Identity\Interfaces\GraphQL\Mutations;

use Cronos\Identity\Application\Commands\ResetPassword;
use Cronos\Identity\Application\Handlers\ResetPasswordHandler;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class ResetPasswordMutation
{
    use ResolvesClientContext;

    public function __construct(private readonly ResetPasswordHandler $handler) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): bool
    {
        $input = $args['input'];
        ($this->handler)(new ResetPassword($input['email'], $input['token'], $input['password'], $this->clientContext($context)));

        return true;
    }
}
