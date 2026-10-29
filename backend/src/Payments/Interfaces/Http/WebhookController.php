<?php

declare(strict_types=1);

namespace Cronos\Payments\Interfaces\Http;

use Cronos\Payments\Application\WebhookReceiver;
use Cronos\Payments\Domain\Exceptions\InvalidWebhook;
use Cronos\Payments\Domain\ValueObjects\Provider;
use Cronos\Payments\Infrastructure\Jobs\ProcessWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /webhooks/{provider}. Deliberately dumb: verify, store, queue, answer. A 2xx tells the provider to stop retrying, so we only
 * send one once the notification is safely in our inbox; a bad signature gets a 400 and is never processed.
 */
final class WebhookController
{
    public function __invoke(Request $request, string $provider, WebhookReceiver $receiver): JsonResponse
    {
        if (! in_array($provider, Provider::ALL, true)) {
            return new JsonResponse(['error' => 'unknown provider'], 404);
        }

        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = (string) ($values[0] ?? '');
        }
        // PHP rewrites the dot of `data.id` in $_GET; Mercado Pago signs the original value, so read it from the raw query string.
        foreach (explode('&', (string) $request->server('QUERY_STRING', '')) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            if (in_array(urldecode($k), ['data.id', 'id'], true) && $v !== '') {
                $headers['x-query-data-id'] ??= urldecode($v);
            }
        }

        try {
            $stored = $receiver->receive($provider, $request->getContent(), $headers, $request->ip());
        } catch (InvalidWebhook $e) {
            return new JsonResponse(['error' => $e->errorCode()], $e->errorCode() === 'WEBHOOK_TOO_LARGE' ? 413 : 400);
        }

        $stored['created'] && ProcessWebhookJob::dispatch($stored['id']);

        return new JsonResponse(['received' => true]);
    }
}
