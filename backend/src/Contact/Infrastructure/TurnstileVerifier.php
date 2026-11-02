<?php

declare(strict_types=1);

namespace Cronos\Contact\Infrastructure;

use Cronos\Contact\Domain\Ports\HumanVerifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile server-side validation (POST siteverify). A token is single-use and lives 5 minutes; it is checked together with the
 * visitor's IP and the action the widget was created with, so a token solved on another form or by another machine does not pass.
 */
final class TurnstileVerifier implements HumanVerifier
{
    public function enabled(): bool
    {
        return (string) config('contact.turnstile.secret') !== '';
    }

    public function verify(?string $token, ?string $ip): string
    {
        if (! $this->enabled()) {
            return self::SKIPPED;
        }
        if ($token === null || trim($token) === '' || strlen($token) > 2048) {
            return self::FAILED; // no token = the widget never ran (a script posting straight to the API)
        }

        try {
            $response = Http::asForm()->timeout((int) config('contact.turnstile.timeout'))->post((string) config('contact.turnstile.verify_url'), array_filter([
                'secret' => (string) config('contact.turnstile.secret'), 'response' => $token, 'remoteip' => $ip,
            ]));
        } catch (\Throwable $e) {
            Log::warning('Turnstile unreachable', ['error' => $e->getMessage()]);

            return self::UNAVAILABLE;
        }
        if ($response->serverError()) {
            Log::warning('Turnstile answered with an error', ['status' => $response->status()]);

            return self::UNAVAILABLE;
        }

        $body = (array) $response->json();
        if (($body['success'] ?? false) !== true) {
            // "internal-error" is Cloudflare's own failure, not the visitor's: do not punish them for it.
            return in_array('internal-error', (array) ($body['error-codes'] ?? []), true) ? self::UNAVAILABLE : self::FAILED;
        }
        $action = (string) config('contact.turnstile.action');
        if ($action !== '' && isset($body['action']) && $body['action'] !== $action) {
            return self::FAILED;
        }
        $host = (string) config('contact.turnstile.hostname', '');
        if ($host !== '' && isset($body['hostname']) && $body['hostname'] !== $host) {
            return self::FAILED;
        }

        return self::PASSED;
    }
}
