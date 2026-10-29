<?php

declare(strict_types=1);

namespace Cronos\Shared\Interfaces\GraphQL;

use Closure;
use Cronos\Shared\Domain\DomainError;
use GraphQL\Error\Error;
use Nuwave\Lighthouse\Execution\ErrorHandler;

final class DomainErrorHandler implements ErrorHandler
{
    public function __invoke(?Error $error, Closure $next): ?array
    {
        if ($error !== null && $error->getPrevious() instanceof DomainError) {
            $error = new Error(
                $error->getMessage(),
                $error->getNodes(),
                $error->getSource(),
                $error->getPositions(),
                $error->getPath(),
                new ClientSafeDomainException($error->getPrevious()),
            );
        }

        return $next($error);
    }
}
