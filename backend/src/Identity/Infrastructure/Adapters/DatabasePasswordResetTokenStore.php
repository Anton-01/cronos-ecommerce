<?php

declare(strict_types=1);

namespace Cronos\Identity\Infrastructure\Adapters;

use Cronos\Identity\Domain\Ports\PasswordResetTokenStore;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabasePasswordResetTokenStore implements PasswordResetTokenStore
{
    private const TTL_MINUTES = 60;

    public function __construct(private readonly Hasher $hasher) {}

    public function create(string $email): string
    {
        $plain = Str::random(64);

        DB::table('password_reset_tokens')->upsert(
            [['email' => $email, 'token' => $this->hasher->make($plain), 'created_at' => now()]],
            ['email'],
            ['token', 'created_at'],
        );

        return $plain;
    }

    public function consume(string $email, string $token): bool
    {
        return DB::transaction(function () use ($email, $token): bool {
            $row = DB::table('password_reset_tokens')->where('email', $email)->lockForUpdate()->first();

            if ($row === null) {
                return false;
            }

            $expired = now()->subMinutes(self::TTL_MINUTES)->isAfter($row->created_at);
            $valid = ! $expired && $this->hasher->check($token, $row->token);

            // Single use: burn the token on expiry too, but keep it after a failed guess.
            if ($valid || $expired) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();
            }

            return $valid;
        });
    }
}
