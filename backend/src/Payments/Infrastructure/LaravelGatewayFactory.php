<?php

declare(strict_types=1);

namespace Cronos\Payments\Infrastructure;

use Cronos\Payments\Application\ProviderCatalog;
use Cronos\Payments\Domain\Exceptions\NotFound;
use Cronos\Payments\Domain\Exceptions\PaymentError;
use Cronos\Payments\Domain\Ports\CircuitBreaker;
use Cronos\Payments\Domain\Ports\GatewayFactory;
use Cronos\Payments\Domain\Ports\PaymentGateway;
use Cronos\Payments\Domain\Ports\ProviderRepository;
use Cronos\Payments\Domain\ValueObjects\Provider;
use Cronos\Payments\Infrastructure\Gateways\GatewayHttp;
use Cronos\Payments\Infrastructure\Gateways\MercadoPagoGateway;
use Cronos\Payments\Infrastructure\Gateways\PayPalGateway;
use Cronos\Payments\Infrastructure\Gateways\StripeGateway;

/** Builds a gateway from the credentials stored for the requested mode, refusing to guess when they are not there. */
final class LaravelGatewayFactory implements GatewayFactory
{
    public function __construct(private readonly ProviderRepository $providers, private readonly CircuitBreaker $breaker) {}

    public function make(string $provider, string $mode): PaymentGateway
    {
        Provider::assertKnown($provider);
        Provider::assertMode($mode);
        $config = $this->providers->find($provider) ?? throw new NotFound('The payment provider');
        $credentials = $config['credentials'][$mode];

        $missing = array_values(array_filter(ProviderCatalog::requiredFields($provider), fn (string $f) => ($credentials[$f] ?? '') === ''));
        // Webhook secrets are only needed to *receive*; creating payments must not be blocked by a missing one.
        $missing = array_values(array_diff($missing, ['webhook_secret', 'webhook_id']));
        $missing === [] || throw new PaymentError(ProviderCatalog::NAMES[$provider]." has no {$mode} credentials: ".implode(', ', $missing).'.', 'PROVIDER_NOT_CONFIGURED');

        $http = new GatewayHttp($this->breaker, (int) config('payments.timeout', 15), (int) config('payments.connect_timeout', 5));

        return match ($provider) {
            Provider::STRIPE => new StripeGateway($http, $credentials, $mode),
            Provider::MERCADOPAGO => new MercadoPagoGateway($http, $credentials, $mode, rtrim((string) (config('payments.webhook_base_url') ?: config('app.url')), '/').'/webhooks/mercadopago'),
            Provider::PAYPAL => new PayPalGateway($http, $credentials, $mode),
        };
    }
}
