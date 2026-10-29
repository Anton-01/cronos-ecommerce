<?php

declare(strict_types=1);

namespace Cronos\Shared\Interfaces\GraphQL;

use Cronos\Shared\Domain\DomainError;
use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;

/** Exposes a DomainError to API clients with a stable machine-readable code. */
final class ClientSafeDomainException extends \RuntimeException implements ClientAware, ProvidesExtensions
{
    public function __construct(private readonly DomainError $error)
    {
        parent::__construct($error->getMessage(), 0, $error);
    }

    public function isClientSafe(): bool
    {
        return true;
    }

    public function getExtensions(): array
    {
        return ['code' => $this->error->errorCode()];
    }
}
