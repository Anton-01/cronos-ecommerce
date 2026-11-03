<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Application;

/**
 * Links in e-mails prove who is asking without a login: `purpose.subscriber.expiry` signed with the application key. The confirmation link lasts
 * a week; the unsubscribe link never expires (a person must always be able to leave, even from a message years old).
 */
final class NewsletterTokens
{
    public const CONFIRM_DAYS = 7;

    public function confirm(string $subscriberId): string
    {
        return $this->sign('confirm', $subscriberId, now()->getTimestamp() + self::CONFIRM_DAYS * 86400);
    }

    public function unsubscribe(string $subscriberId): string
    {
        return $this->sign('unsub', $subscriberId, 0);
    }

    /** @return array{purpose: string, id: string, expired: bool}|null null when the token was not made by us */
    public function read(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        $payload = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($payload === false || ! hash_equals($this->mac($payload), $parts[1])) {
            return null;
        }
        [$purpose, $id, $exp] = array_pad(explode('|', $payload), 3, '');

        return ['purpose' => $purpose, 'id' => $id, 'expired' => (int) $exp > 0 && (int) $exp < now()->getTimestamp()];
    }

    private function sign(string $purpose, string $id, int $expires): string
    {
        $payload = "{$purpose}|{$id}|{$expires}";

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=').'.'.$this->mac($payload);
    }

    private function mac(string $payload): string
    {
        return hash_hmac('sha256', 'newsletter|'.$payload, (string) config('app.key'));
    }
}
