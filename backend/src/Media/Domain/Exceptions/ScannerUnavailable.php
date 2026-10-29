<?php

declare(strict_types=1);

namespace Cronos\Media\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class ScannerUnavailable extends DomainError
{
    public function __construct(string $detail = '')
    {
        parent::__construct('The file could not be scanned right now. Please try again in a moment.'.($detail !== '' ? " ({$detail})" : ''));
    }

    public function errorCode(): string
    {
        return 'SCANNER_UNAVAILABLE';
    }
}
