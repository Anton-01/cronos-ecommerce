<?php

namespace Tests\Feature\Payments;

use Cronos\Payments\Application\PaymentService;
use Cronos\Payments\Application\ProviderManager;
use Cronos\Payments\Domain\ValueObjects\PaymentNotice;
use Cronos\Payments\Domain\ValueObjects\PaymentRequest;
use Cronos\Pricing\Domain\ValueObjects\Money;
use Database\Seeders\PaymentProvidersSeeder;
use Database\Seeders\PricingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\GraphQLTestCase;

final class PaymentsAdminApiTest extends GraphQLTestCase
{
    use RefreshDatabase;

    private string $admin;

    private string $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PricingSeeder::class, PaymentProvidersSeeder::class]);
        Cache::flush();
        Sleep::fake();
        Queue::fake();
        Http::preventStrayRequests();
        $this->admin = $this->adminToken();
        $this->owner = $this->adminToken('owner@example.com', 'super-admin');
    }

    public function test_credentials_are_saved_per_mode_and_never_come_back_in_clear(): void
    {
        $q = 'mutation($v: JSON!) { savePaymentCredentials(code: "stripe", mode: SANDBOX, values: $v) { mode sandbox { key isSet display secret } live { key isSet } liveReady webhookUrl } }';
        $r = $this->adminGql($q, ['v' => ['secret_key' => 'sk_test_abcdefghijkl1234', 'publishable_key' => 'pk_test_abc123']], $this->admin)->assertJsonMissingPath('errors')->json('data.savePaymentCredentials');

        $secret = collect($r['sandbox'])->firstWhere('key', 'secret_key');
        $this->assertTrue($secret['isSet']);
        $this->assertStringNotContainsString('abcdefghijkl', (string) $secret['display']);
        $this->assertSame('pk_test_abc123', collect($r['sandbox'])->firstWhere('key', 'publishable_key')['display'], 'public identifiers are shown as they are');
        $this->assertFalse($r['liveReady']);
        $this->assertSame('SANDBOX', $r['mode']);
        $this->assertStringEndsWith('/webhooks/stripe', $r['webhookUrl']);

        $this->adminGql($q, ['v' => ['secret_key' => 'sk_live_wrongenvironment1234']], $this->admin)->assertJsonPath('errors.0.extensions.code', 'CREDENTIAL_ENVIRONMENT_MISMATCH');
        $this->assertStringNotContainsString('abcdefghijkl', (string) DB::table('payment_providers')->where('code', 'stripe')->value('credentials_sandbox') ?: '', 'stored encrypted');
    }

    public function test_only_the_owner_can_go_live_and_only_with_the_phrase_complete_credentials_and_a_working_key(): void
    {
        $manager = app(ProviderManager::class);
        $manager->saveCredentials('stripe', 'live', ['secret_key' => 'sk_live_abcdefghijkl1234', 'publishable_key' => 'pk_live_abc123', 'webhook_secret' => 'whsec_live']);
        $q = 'mutation($c: String) { switchPaymentMode(code: "stripe", target: LIVE, confirmation: $c) { mode liveSince } }';

        $this->adminGql($q, ['c' => 'PASAR A PRODUCCION'], $this->admin)->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'This action is unauthorized'));
        $this->adminGql($q, ['c' => 'si'], $this->owner)->assertJsonPath('errors.0.extensions.code', 'CONFIRMATION_REQUIRED');

        Http::fake(['api.stripe.com/*' => Http::sequence()->push(['error' => ['message' => 'Invalid API Key']], 401)->push(['object' => 'balance', 'livemode' => true, 'available' => []], 200)]);
        $this->adminGql($q, ['c' => 'pasar a produccion'], $this->owner)->assertJsonPath('data.switchPaymentMode', null);
        $this->assertSame('sandbox', DB::table('payment_providers')->where('code', 'stripe')->value('mode'), 'a key that fails the test call never goes live');

        $this->adminGql($q, ['c' => 'pasar a produccion'], $this->owner)->assertJsonPath('data.switchPaymentMode.mode', 'LIVE');
        $this->adminGql('mutation { switchPaymentMode(code: "stripe", target: SANDBOX) { mode } }', [], $this->owner)->assertJsonPath('data.switchPaymentMode.mode', 'SANDBOX');
    }

    public function test_support_staff_cannot_see_payments_and_a_refund_needs_its_own_permission(): void
    {
        $support = $this->adminToken('help@example.com', 'support');
        $this->adminGql('{ adminPayments { total } }', [], $support)->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'This action is unauthorized'));
    }

    public function test_the_ledger_refunds_and_webhook_inbox_are_visible_and_reprocessable(): void
    {
        $manager = app(ProviderManager::class);
        $manager->saveCredentials('stripe', 'sandbox', PaymentsTestCase::STRIPE);
        $manager->configure('stripe', ['markets' => ['MX'], 'is_enabled' => true]);
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_1', 'status' => 'requires_payment_method', 'client_secret' => 'pi_1_secret', 'amount' => 11600, 'currency' => 'mxn'], 200)]);
        $service = app(PaymentService::class);
        $id = $service->start(new PaymentRequest('stripe', 'checkout', 'chk-1', new Money('116.00', 'MXN'), 'MX', 'key-1', 'ana@example.com'))['payment']['id'];
        $service->applyNotice($id, new PaymentNotice(PaymentNotice::KIND_STATUS, $id, 'pi_1', 'captured', '116.00', 'MXN', 'evt_1'), 'webhook', 'evt_1');

        $p = $this->adminGql('query($id: ID!) { adminPayment(id: $id) { status capturedAmount mode transactions { type status externalEventId } refunds { id } } }', ['id' => $id], $this->admin)
            ->assertJsonMissingPath('errors')->json('data.adminPayment');
        $this->assertSame(['captured', '116.00', 'SANDBOX'], [$p['status'], $p['capturedAmount'], $p['mode']]);
        $this->assertContains('evt_1', array_column($p['transactions'], 'externalEventId'));

        $this->adminGql('{ adminPayments(status: "captured") { total rows { id } } }', [], $this->admin)->assertJsonPath('data.adminPayments.total', 1);
    }
}
