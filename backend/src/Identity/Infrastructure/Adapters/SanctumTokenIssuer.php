<?php

declare(strict_types=1);

namespace Cronos\Identity\Infrastructure\Adapters;

use Cronos\Identity\Domain\Ports\TokenIssuer;
use Cronos\Identity\Domain\ValueObjects\IssuedToken;
use Cronos\Identity\Infrastructure\Persistence\Eloquent\UserModel;
use Laravel\Sanctum\PersonalAccessToken;

final class SanctumTokenIssuer implements TokenIssuer
{
    public function issue(string $userId, string $deviceName, array $abilities): IssuedToken
    {
        $ttlMinutes = (int) config('sanctum.expiration');
        $expiresAt = $ttlMinutes > 0 ? now()->addMinutes($ttlMinutes) : null;

        $token = UserModel::query()->findOrFail($userId)->createToken($deviceName, $abilities, $expiresAt);

        return new IssuedToken($token->plainTextToken, $expiresAt?->toImmutable());
    }

    public function revokeCurrent(string $userId): void
    {
        // The token in use lives on the request's authenticated user instance.
        $user = request()->user();
        $current = $user instanceof UserModel && $user->getKey() === $userId ? $user->currentAccessToken() : null;

        if ($current instanceof PersonalAccessToken) {
            $current->delete();
        }
    }

    public function revokeAll(string $userId): void
    {
        UserModel::query()->findOrFail($userId)->tokens()->delete();
    }
}
