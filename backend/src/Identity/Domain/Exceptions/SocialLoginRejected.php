<?php

declare(strict_types=1);

namespace Cronos\Identity\Domain\Exceptions;

use Cronos\Shared\Domain\DomainError;

final class SocialLoginRejected extends DomainError
{
    public function __construct(string $reason = 'The social login could not be completed.')
    {
        parent::__construct($reason);
    }

    public function errorCode(): string
    {
        return 'SOCIAL_LOGIN_REJECTED';
    }
}
