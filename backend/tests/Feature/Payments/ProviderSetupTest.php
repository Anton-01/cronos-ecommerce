<?php

namespace Tests\Feature\Payments;

use Cronos\Payments\Application\ProviderCatalog;
use Cronos\Payments\Application\ProviderManager;
use Cronos\Payments\Domain\Exceptions\PaymentError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class ProviderSetupTest extends PaymentsTestCase
{
    private function manager(): ProviderManager
    {
        return app(ProviderManager::class);
    }

    public function test_credentials_are_encrypted_at_rest_and_never_come_back_whole(): void
    {
        $raw = DB::table('payment_providers')->where('code', 'stripe')->value('credentials_sandbox');

        $this->assertStringNotContainsString('sk_test_abc123', $raw);
        $this->assertSame('sk_test_abc123', $this->providers()->find('stripe')['credentials']['sandbox']['secret_key']);
        $this->assertSame('sk_t…c123', ProviderCatalog::mask('sk_test_abc123', true));
    }

    public function test_saving_one_field_keeps_the_others_and_an_empty_field_changes_nothing(): void
    {
        $this->manager()->saveCredentials('stripe', 'sandbox', ['publishable_key' => 'pk_test_new', 'secret_key' => '']);

        $c = $this->providers()->find('stripe')['credentials']['sandbox'];
        $this->assertSame(['sk_test_abc123', 'pk_test_new', 'whsec_stripe'], [$c['secret_key'], $c['publishable_key'], $c['webhook_secret']]);
    }

    public function test_a_live_key_cannot_be_saved_as_a_sandbox_key_or_the_reverse(): void
    {
        foreach ([['sandbox', 'secret_key', 'sk_live_abc'], ['live', 'secret_key', 'sk_test_abc'], ['live', 'publishable_key', 'pk_test_abc']] as [$mode, $field, $value]) {
            try {
                $this->manager()->saveCredentials('stripe', $mode, [$field => $value]);
                $this->fail("{$value} accepted in {$mode}");
            } catch (PaymentError $e) {
                $this->assertSame('CREDENTIAL_ENVIRONMENT_MISMATCH', $e->errorCode());
            }
        }

        $this->expectException(PaymentError::class);
        $this->manager()->saveCredentials('stripe', 'sandbox', ['nope' => 'x']);
    }

    public function test_changing_credentials_leaves_a_security_trail_without_the_values(): void
    {
        $this->manager()->saveCredentials('paypal', 'sandbox', ['client_secret' => 'a-new-secret-value']);

        $event = DB::table('security_events')->where('event', 'payment_credentials_changed')->orderByDesc('id')->first();
        $context = json_decode($event->context, true);
        $this->assertSame(['paypal', 'sandbox'], [$context['provider'], $context['mode']]);
        $this->assertStringNotContainsString('a-new-secret-value', $event->context);
        $this->assertSame(0, DB::table('audits')->where('new_values', 'like', '%a-new-secret-value%')->count());
        $this->assertSame(0, DB::table('audits')->where('new_values', 'like', '%sk_test_abc123%')->count());
    }

    public function test_a_gateway_cannot_be_enabled_without_credentials_for_its_current_mode(): void
    {
        DB::table('payment_providers')->where('code', 'paypal')->update(['credentials_sandbox' => null, 'is_enabled' => false]);

        try {
            $this->manager()->configure('paypal', ['is_enabled' => true]);
            $this->fail('expected refusal');
        } catch (PaymentError $e) {
            $this->assertSame('CREDENTIALS_INCOMPLETE', $e->errorCode());
            $this->assertStringContainsString('client_id', $e->getMessage());
        }
    }

    public function test_markets_and_limits_are_validated(): void
    {
        foreach ([['markets' => ['ZZ']], ['min_amount' => '-1'], ['min_amount' => '50', 'max_amount' => '10']] as $bad) {
            try {
                $this->manager()->configure('stripe', $bad);
                $this->fail('expected refusal');
            } catch (PaymentError) {
                $this->addToAssertionCount(1);
            }
        }
        $this->manager()->configure('stripe', ['markets' => ['mx', 'us'], 'min_amount' => '10', 'max_amount' => '50000']);
        $this->assertSame(['MX', 'US'], $this->providers()->find('stripe')['markets']);
    }

    public function test_going_live_needs_the_phrase_complete_live_credentials_and_a_working_connection(): void
    {
        // 1. no confirmation
        try {
            $this->manager()->switchMode('stripe', 'live', 'sí', null);
            $this->fail('expected refusal');
        } catch (PaymentError $e) {
            $this->assertSame('CONFIRMATION_REQUIRED', $e->errorCode());
        }
        // 2. no live credentials
        try {
            $this->manager()->switchMode('stripe', 'live', 'pasar a producción', null);
            $this->fail('expected refusal');
        } catch (PaymentError $e) {
            $this->assertSame('CREDENTIALS_INCOMPLETE', $e->errorCode());
        }
        $this->manager()->saveCredentials('stripe', 'live', ['secret_key' => 'sk_live_real', 'publishable_key' => 'pk_live_real', 'webhook_secret' => 'whsec_live']);

        // 3. the key is rejected by Stripe: the switch must not happen
        Http::fake(['api.stripe.com/v1/balance' => Http::sequence()
            ->push(['error' => ['message' => 'Invalid API Key provided', 'code' => 'api_key_invalid']], 401)
            ->push(['livemode' => false], 200)
            ->push(['livemode' => true], 200)]);
        try {
            $this->manager()->switchMode('stripe', 'live', 'PASAR A PRODUCCION', 'user-1');
            $this->fail('expected refusal');
        } catch (PaymentError) {
            $this->assertSame('sandbox', $this->providers()->find('stripe')['mode']);
        }

        // 4. a *test* account behind a live key name is caught by the livemode flag
        try {
            $this->manager()->switchMode('stripe', 'live', 'PASAR A PRODUCCION', 'user-1');
            $this->fail('expected refusal');
        } catch (PaymentError $e) {
            $this->assertSame('CREDENTIAL_ENVIRONMENT_MISMATCH', $e->errorCode());
        }

        // 5. everything right
        $this->manager()->switchMode('stripe', 'live', '  pasar a   PRODUCCIÓN ', 'user-1');
        $p = $this->providers()->find('stripe');
        $this->assertSame(['live', 'user-1'], [$p['mode'], rtrim((string) DB::table('payment_providers')->where('code', 'stripe')->value('live_enabled_by'))]);
        $this->assertNotNull($p['live_since']);

        // and back to the test environment whenever wanted, no ceremony
        $this->manager()->switchMode('stripe', 'sandbox', null, 'user-1');
        $this->assertSame('sandbox', $this->providers()->find('stripe')['mode']);
    }

    public function test_the_mode_change_is_in_the_audit_trail(): void
    {
        $this->manager()->saveCredentials('stripe', 'live', ['secret_key' => 'sk_live_real', 'publishable_key' => 'pk_live_real', 'webhook_secret' => 'whsec_live']);
        Http::fake(['api.stripe.com/v1/balance' => Http::response(['livemode' => true], 200)]);
        $this->manager()->switchMode('stripe', 'live', 'PASAR A PRODUCCION', null);

        $audit = DB::table('audits')->where('auditable_type', 'like', '%PaymentProviderModel')->where('new_values', 'like', '%"mode": "live"%')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('sk_live_real', (string) $audit->new_values);
    }

    public function test_each_provider_checks_its_credentials_with_a_harmless_call(): void
    {
        Http::fake([
            'api.stripe.com/v1/balance' => Http::response(['livemode' => false], 200),
            'api.mercadopago.com/users/me' => Http::response(['nickname' => 'TESTUSER', 'site_id' => 'MLM'], 200),
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'A21', 'expires_in' => 32400], 200),
        ]);

        $this->assertStringContainsString('test mode', $this->manager()->testConnection('stripe', 'sandbox'));
        $this->assertStringContainsString('TESTUSER', $this->manager()->testConnection('mercadopago', 'sandbox'));
        $this->assertStringContainsString('PayPal', $this->manager()->testConnection('paypal', 'sandbox'));

        Http::assertSent(fn ($r) => $r->url() === 'https://api.stripe.com/v1/balance' && $r->hasHeader('Authorization', 'Bearer sk_test_abc123'));
    }
}
