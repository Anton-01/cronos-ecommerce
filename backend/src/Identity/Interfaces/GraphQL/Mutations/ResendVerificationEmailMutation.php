<?php

declare(strict_types=1);

namespace Cronos\Identity\Interfaces\GraphQL\Mutations;

use Cronos\Identity\Application\Commands\ResendVerificationEmail;
use Cronos\Identity\Application\Handlers\ResendVerificationEmailHandler;
use Cronos\Shared\Interfaces\GraphQL\ResolvesClientContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class ResendVerificationEmailMutation
{
    use ResolvesClientContext;

    public function __construct(private readonly ResendVerificationEmailHandler $handler) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): bool
    {
        ($this->handler)(new ResendVerificationEmail((string) $context->user()->getAuthIdentifier()));

        return true;
    }
}
