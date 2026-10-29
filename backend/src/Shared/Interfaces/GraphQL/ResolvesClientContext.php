<?php

declare(strict_types=1);

namespace Cronos\Shared\Interfaces\GraphQL;

use Cronos\Shared\Domain\ClientContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

trait ResolvesClientContext
{
    private function clientContext(GraphQLContext $context): ClientContext
    {
        return new ClientContext($context->request()->ip(), $context->request()->userAgent());
    }
}
