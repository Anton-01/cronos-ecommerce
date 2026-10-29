<?php

declare(strict_types=1);

namespace Cronos\Shared\Domain;

/**
 * Base class for expected business failures. The machine-readable code is
 * surfaced to API clients (extensions.code) by the interface layer.
 */
abstract class DomainError extends \DomainException
{
    abstract public function errorCode(): string;
}
