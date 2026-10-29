<?php

declare(strict_types=1);

namespace Cronos\Payments\Application;

use Cronos\Payments\Domain\Exceptions\InvalidWebhook;
use Cronos\Payments\Domain\Exceptions\NotFound;
use Cronos\Payments\Domain\Ports\GatewayFactory;
use Cronos\Payments\Domain\Ports\ProviderRepository;
use Cronos\Payments\Domain\Ports\WebhookInbox;
use Cronos\Payments\Domain\ValueObjects\Provider;

/**
 * The only thing the public webhook endpoint does: check the signature where that is cheap, store the raw notification once,
 * and hand it to the queue. No business logic and no calls to the provider here, so the answer is fast and a provider outage or
 * a bug in processing can never make us lose or reject a legitimate notification.
 */
final class WebhookReceiver
{
    private const MAX_BODY = 262144;

    public function __construct(
        private readonly GatewayFactory $gateways,
        private readonly ProviderRepository $providers,
        private readonly WebhookInbox $inbox,
    ) {}

    /**
     * @param  array<string, string>  $headers  lower-cased header name => value
     * @return array{id: string, created: bool}
     *
     * @throws InvalidWebhook when the signature does not verify with any configured mode
     */
    public function receive(string $provider, string $rawBody, array $headers, ?string $ip): array
    {
        Provider::assertKnown($provider);
        strlen($rawBody) <= self::MAX_BODY || throw new InvalidWebhook('The notification is too large.', 'WEBHOOK_TOO_LARGE');
        $config = $this->providers->find($provider) ?? throw new NotFound('The provider');

        // The configured mode is tried first, then the other one: a sandbox notification reaching a live shop (or vice versa) is
        // recognised for what it is instead of being mistaken for a forgery.
        $modes = $config['mode'] === Provider::LIVE ? [Provider::LIVE, Provider::SANDBOX] : [Provider::SANDBOX, Provider::LIVE];
        $verifiedMode = null;
        $deferred = false;

        foreach ($modes as $mode) {
            if (! $this->hasCredentials($config, $mode)) {
                continue;
            }
            $gateway = $this->gateways->make($provider, $mode);
            if (! $gateway->verifiesLocally()) {
                $deferred = true;
                $verifiedMode ??= $mode === $config['mode'] ? $mode : null;

                continue; // checked later, in the queue, by asking the provider
            }
            if ($gateway->verifyWebhook($rawBody, $headers)) {
                $verifiedMode = $mode;
                break;
            }
        }

        $gateway = $this->gateways->make($provider, $verifiedMode ?? $config['mode']);
        $identity = $gateway->identify($rawBody, $headers);

        if ($verifiedMode === null && ! $deferred) {
            // Forensics without amplification: keep a trace (deduplicated by body hash), answer 400, never process it.
            $this->inbox->store([
                'provider' => $provider, 'mode' => null, 'event_id' => 'rejected:'.substr(hash('sha256', $rawBody), 0, 40), 'event_type' => $identity['type'], 'signature_valid' => false,
                'raw_body' => mb_substr($rawBody, 0, 8192), 'headers' => $this->safeHeaders($headers), 'status' => 'rejected', 'ip_address' => $ip,
            ]);

            throw new InvalidWebhook('The notification signature is not valid.', 'INVALID_SIGNATURE');
        }

        return $this->inbox->store([
            'provider' => $provider, 'mode' => $verifiedMode, 'event_id' => $identity['id'], 'event_type' => $identity['type'], 'signature_valid' => $verifiedMode !== null && ! $deferred,
            'raw_body' => $rawBody, 'headers' => $this->safeHeaders($headers), 'status' => 'received', 'next_attempt_at' => now(), 'ip_address' => $ip,
        ]);
    }

    /** @param array<string, mixed> $config */
    private function hasCredentials(array $config, string $mode): bool
    {
        return ($config['credentials'][$mode] ?? []) !== [];
    }

    /** Only what is needed to re-verify and to investigate: never cookies or authorisation headers. @param array<string, string> $headers @return array<string, string> */
    private function safeHeaders(array $headers): array
    {
        return array_intersect_key($headers, array_flip([
            'content-type', 'user-agent', 'stripe-signature', 'x-signature', 'x-request-id', 'x-query-data-id', 'paypal-transmission-id', 'paypal-transmission-time',
            'paypal-transmission-sig', 'paypal-cert-url', 'paypal-auth-algo', 'x-forwarded-for',
        ]));
    }
}
