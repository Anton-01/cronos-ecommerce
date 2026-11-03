<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Interfaces\Http;

use Cronos\Newsletter\Application\ResendWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /webhooks/resend. Resend signs with Svix: HMAC-SHA256 of "{id}.{timestamp}.{body}" with the base64 part of the `whsec_` secret, compared with every
 * `v1,<signature>` in the header; a timestamp more than 5 minutes off is refused (replay). Anything that does not verify gets a 400 and is not looked at.
 */
final class ResendWebhookController
{
    public function __invoke(Request $request, ResendWebhook $webhook): JsonResponse
    {
        $secret = (string) config('newsletter.resend.webhook_secret');
        $id = (string) $request->header('svix-id');
        $timestamp = (string) $request->header('svix-timestamp');
        $body = $request->getContent();

        if ($secret === '' || ! $this->valid($secret, $id, $timestamp, $body, (string) $request->header('svix-signature'))) {
            return new JsonResponse(['error' => 'invalid signature'], 400);
        }
        $event = json_decode($body, true);
        is_array($event) && $webhook->handle($event);

        return new JsonResponse(['ok' => true]);
    }

    private function valid(string $secret, string $id, string $timestamp, string $body, string $header): bool
    {
        if ($id === '' || ! ctype_digit($timestamp) || abs(now()->getTimestamp() - (int) $timestamp) > 300) {
            return false;
        }
        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);
        if ($key === false) {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
        foreach (explode(' ', trim($header)) as $part) {
            [$version, $signature] = array_pad(explode(',', $part, 2), 2, '');
            if ($version === 'v1' && hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
