<?php

declare(strict_types=1);

namespace Cronos\Identity\Interfaces\GraphQL;

use Cronos\Identity\Application\AuthResult;
use Cronos\Identity\Infrastructure\Persistence\Eloquent\UserModel;

final class AuthPayloadFactory
{
    /** @return array{token: string, expiresAt: ?\DateTimeImmutable, user: UserModel} */
    public static function from(AuthResult $result): array
    {
        return [
            'token' => $result->token->plainText,
            'expiresAt' => $result->token->expiresAt,
            'user' => UserModel::query()->findOrFail($result->user->id),
        ];
    }
}
