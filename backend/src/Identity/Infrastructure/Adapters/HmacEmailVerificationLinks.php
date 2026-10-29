<?php

declare(strict_types=1);

namespace Cronos\Identity\Infrastructure\Adapters;

use Cronos\Identity\Domain\Ports\EmailVerificationLinks;

/**
 * Stateless signed links: HMAC over id|email|expiry with the app key.
 * Changing the email address invalidates outstanding links automatically.
 */
final class HmacEmailVerificationLinks implements EmailVerificationLinks
{
    private const TTL_SECONDS = 86400;

    public function make(string $userId, string $email): string
    {
        $expires = time() + self::TTL_SECONDS;

        return rtrim((string) config('app.storefront_url'), '/').'/verify-email?'.http_build_query([
            'id' => $userId,
            'expires' => $expires,
            'signature' => $this->sign($userId, $email, $expires),
        ]);
    }

    public function isValid(string $userId, string $email, int $expires, string $signature): bool
    {
        return $expires >= time() && hash_equals($this->sign($userId, $email, $expires), $signature);
    }

    private function sign(string $userId, string $email, int $expires): string
    {
        return hash_hmac('sha256', "{$userId}|".mb_strtolower($email)."|{$expires}", $this->key());
    }

    private function key(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key;
    }
}
