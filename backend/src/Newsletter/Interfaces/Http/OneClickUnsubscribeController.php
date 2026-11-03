<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Interfaces\Http;

use Cronos\Newsletter\Application\NewsletterService;
use Cronos\Newsletter\Domain\Exceptions\NewsletterError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RFC 8058 one-click unsubscribe: the mail app POSTs `List-Unsubscribe=One-Click` to the URL in the List-Unsubscribe header and the person is off the list,
 * with no page and no login. Gmail and Apple Mail require it from bulk senders. Only POST: a link prefetched by a scanner (GET) changes nothing.
 */
final class OneClickUnsubscribeController
{
    public function __invoke(Request $request, NewsletterService $newsletter): JsonResponse
    {
        try {
            $newsletter->unsubscribe((string) $request->query('token'));
        } catch (NewsletterError) {
            return new JsonResponse(['error' => 'invalid token'], 400);
        }

        return new JsonResponse(['ok' => true]);
    }
}
