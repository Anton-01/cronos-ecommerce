<?php

declare(strict_types=1);

namespace Cronos\Shared\Interfaces\GraphQL;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Propagates (or creates) a request correlation id into logs and the response. */
final class CorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) ($request->header('X-Correlation-Id') ?: Str::ulid());
        $id = Str::limit(preg_replace('/[^A-Za-z0-9_\-]/', '', $id) ?: (string) Str::ulid(), 64, '');

        Log::shareContext(['correlation_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Correlation-Id', $id);

        return $response;
    }
}
