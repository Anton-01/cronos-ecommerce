<?php

namespace Tests\Feature\Payments;

use Cronos\Payments\Application\PaymentService;
use Cronos\Payments\Application\ProviderManager;
use Cronos\Payments\Domain\Ports\ProviderRepository;
use Cronos\Payments\Domain\ValueObjects\PaymentRequest;
use Cronos\Pricing\Domain\ValueObjects\Money;
use Database\Seeders\PaymentProvidersSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Feature\Pricing\PricingTestCase;

abstract class PaymentsTestCase extends PricingTestCase
{
    public const STRIPE = ['secret_key' => 'sk_test_abc123', 'publishable_key' => 'pk_test_abc123', 'webhook_secret' => 'whsec_stripe'];

    public const MP = ['access_token' => 'TEST-1234-token', 'public_key' => 'TEST-pub', 'webhook_secret' => 'mp_secret'];

    public const PAYPAL = ['client_id' => 'paypal-client', 'client_secret' => 'paypal-secret', 'webhook_id' => 'WH-1'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PaymentProvidersSeeder::class);
        Cache::flush();
        Sleep::fake();
        Http::preventStrayRequests();

        $manager = app(ProviderManager::class);
        $manager->saveCredentials('stripe', 'sandbox', self::STRIPE);
        $manager->saveCredentials('mercadopago', 'sandbox', self::MP);
        $manager->saveCredentials('paypal', 'sandbox', self::PAYPAL);
        $manager->configure('stripe', ['markets' => ['US', 'MX'], 'is_enabled' => true]);
        $manager->configure('mercadopago', ['markets' => ['MX'], 'is_enabled' => true]);
        $manager->configure('paypal', ['markets' => ['US'], 'is_enabled' => true]);
    }

    protected function request(string $provider = 'stripe', string $amount = '116.00', string $currency = 'MXN', string $key = 'key-1', string $market = 'MX', string $payable = 'chk-1'): PaymentRequest
    {
        return new PaymentRequest($provider, 'checkout', $payable, new Money($amount, $currency), $market, $key, 'ana@example.com', 'Pedido de prueba', 'https://shop.test/return', 'https://shop.test/cancel');
    }

    protected function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    protected function stripeIntent(string $status = 'requires_payment_method', string $id = 'pi_1', array $extra = []): array
    {
        return ['id' => $id, 'object' => 'payment_intent', 'status' => $status, 'client_secret' => $id.'_secret_xyz', 'amount' => 11600, 'currency' => 'mxn'] + $extra;
    }

    protected function fakeStripeCreate(string $status = 'requires_payment_method'): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response($this->stripeIntent($status), 200)]);
    }

    /** Signs a Stripe webhook body the way Stripe does. @return array<string, string> */
    protected function stripeHeaders(string $body, ?string $secret = null, ?int $at = null): array
    {
        $t = $at ?? time();

        return ['stripe-signature' => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$body, $secret ?? self::STRIPE['webhook_secret'])];
    }

    protected function stripeEvent(string $id, string $type, array $object): string
    {
        return json_encode(['id' => $id, 'type' => $type, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
    }

    protected function providers(): ProviderRepository
    {
        return app(ProviderRepository::class);
    }
}
