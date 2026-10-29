<?php

declare(strict_types=1);

namespace Cronos\Identity\Interfaces\GraphQL\Mutations;

use Cronos\Identity\Application\Commands\VerifyEmail;
use Cronos\Identity\Application\Handlers\VerifyEmailHandler;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class VerifyEmailMutation
{
    use ResolvesClientContext;

    public function __construct(private readonly VerifyEmailHandler $handler) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): bool
    {
        $input = $args['input'];
        ($this->handler)(new VerifyEmail($input['userId'], $input['expires'], $input['signature']));

        return true;
    }
}
