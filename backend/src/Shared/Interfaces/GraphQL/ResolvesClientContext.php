<?php

declare(strict_types=1);

namespace Cronos\Shared\Interfaces\GraphQL;

use Cronos\Shared\Domain\ClientContext;
use Cronos\Shared\Infrastructure\Geo\CurrentClient;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

trait ResolvesClientContext
{
    private function clientContext(GraphQLContext $context): ClientContext
    {
        return app(CurrentClient::class)->context();
    }
}
