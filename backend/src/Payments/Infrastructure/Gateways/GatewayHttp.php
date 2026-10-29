<?php

declare(strict_types=1);

namespace Cronos\Payments\Infrastructure\Gateways;

use Cronos\Payments\Domain\Exceptions\GatewayUnavailable;
use Cronos\Payments\Domain\Exceptions\PaymentError;
use Cronos\Payments\Domain\Ports\CircuitBreaker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * One place for how the shop talks to a provider: timeouts, a circuit breaker, and retries that are only ever done for calls that
 * carry an idempotency key (or only read). Network errors, 5xx and 429 are "the provider is not answering" (safe to try again);
 * any other 4xx is a definite answer and is never retried.
 */
final class GatewayHttp
{
    private const BACKOFF_MS = [200, 800, 2500];

    public function __construct(private readonly CircuitBreaker $breaker, private readonly int $timeout = 15, private readonly int $connectTimeout = 5) {}

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $json
     * @param  array<string, mixed>|null  $form
     * @param  bool  $safeToRetry  the call is a read or carries an idempotency key
     */
    public function send(string $provider, string $mode, string $method, string $url, array $headers = [], ?array $json = null, ?array $form = null, ?array $query = null, bool $safeToRetry = true, ?array $basic = null): Response
    {
        $name = "{$provider}:{$mode}";
        $this->breaker->allows($name) || throw new GatewayUnavailable($provider, 'circuit open');

        $attempts = $safeToRetry ? count(self::BACKOFF_MS) + 1 : 1;
        $last = null;

        for ($i = 0; $i < $attempts; $i++) {
            $i > 0 && Sleep::usleep(self::BACKOFF_MS[$i - 1] * 1000);

            try {
                $response = $this->request($headers, $basic)->send($method, $url, array_filter(['json' => $json, 'form_params' => $form, 'query' => $query], fn ($v) => $v !== null));
            } catch (ConnectionException $e) {
                $this->breaker->recordFailure($name);
                $last = new GatewayUnavailable($provider, 'connection: '.mb_substr($e->getMessage(), 0, 120));

                continue;
            }

            if ($response->serverError() || $response->status() === 429) {
                $this->breaker->recordFailure($name);
                $last = new GatewayUnavailable($provider, 'HTTP '.$response->status());
                if ($response->status() === 429) {
                    Sleep::usleep(min(5, max(0, (int) $response->header('Retry-After'))) * 1_000_000);
                }

                continue;
            }

            $this->breaker->recordSuccess($name);

            return $response;
        }

        throw $last ?? new GatewayUnavailable($provider);
    }

    /** Turns a definite provider refusal into our error type, keeping the provider's own message for the logs and the admin. */
    public function refuse(Response $response, string $provider, string $fallbackCode = 'PAYMENT_REFUSED'): never
    {
        $message = $response->json('error.message') ?? $response->json('message') ?? $response->json('details.0.description') ?? $response->json('error_description') ?? "HTTP {$response->status()}";
        $code = $response->json('error.code') ?? $response->json('error') ?? $response->json('name') ?? $fallbackCode;

        throw new PaymentError(mb_substr("{$provider}: {$message}", 0, 400), is_string($code) ? mb_strtoupper(preg_replace('/[^A-Za-z0-9_]+/', '_', $code) ?? $fallbackCode) : $fallbackCode);
    }

    /** @param array<string, string> $headers @param array{0: string, 1: string}|null $basic */
    private function request(array $headers, ?array $basic): PendingRequest
    {
        $request = Http::withHeaders($headers)->timeout($this->timeout)->connectTimeout($this->connectTimeout)->throwIf(false);

        return $basic === null ? $request : $request->withBasicAuth($basic[0], $basic[1]);
    }
}
