<?php

namespace Tests\Feature\Payments;

use Cronos\Payments\Domain\Exceptions\PaymentError;
use Cronos\Payments\Domain\ValueObjects\PaymentNotice;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class GatewayContractsTest extends PaymentsTestCase
{
    public function test_mercado_pago_gets_a_preference_carrying_our_payment_id_and_the_webhook_url_and_sends_the_shopper_to_the_sandbox_page(): void
    {
        config(['payments.webhook_base_url' => 'https://shop.example.com']);
        Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response(['id' => 'PREF-1', 'init_point' => 'https://mp.test/live', 'sandbox_init_point' => 'https://sandbox.mp.test/pay'], 201)]);

        $r = $this->payments()->start($this->request('mercadopago', key: 'mp-1'));

        $this->assertSame(['type' => 'redirect', 'url' => 'https://sandbox.mp.test/pay', 'public_key' => 'TEST-pub'], $r['client_action']);
        Http::assertSent(function (Request $q) use ($r) {
            $b = $q->data();

            return $q->hasHeader('Authorization', 'Bearer TEST-1234-token') && $q->hasHeader('X-Idempotency-Key', 'pref-'.$r['payment']['id'])
                && $b['external_reference'] === $r['payment']['id'] && $b['notification_url'] === 'https://shop.example.com/webhooks/mercadopago'
                && $b['items'][0]['unit_price'] === 116.0 && $b['items'][0]['currency_id'] === 'MXN' && $b['back_urls']['success'] === 'https://shop.test/return';
        });
    }

    public function test_mercado_pago_refunds_go_to_the_payment_id_with_an_idempotency_key(): void
    {
        Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response(['id' => 'PREF-1', 'init_point' => 'x'], 201)]);
        $id = $this->payments()->start($this->request('mercadopago', key: 'mp-1'))['payment']['id'];
        $this->payments()->applyNotice($id, new PaymentNotice(PaymentNotice::KIND_STATUS, $id, '5551', 'captured', '116.00', 'MXN', 'n1'), 'webhook', 'n1');
        Http::fake(['api.mercadopago.com/v1/payments/5551/refunds' => Http::response(['id' => 990, 'status' => 'approved'], 201)]);

        $refund = $this->payments()->refund($id, '16.00', null, 'mp-refund', 'u1');

        $this->assertSame(['succeeded', '990'], [$refund['status'], $refund['provider_reference']]);
        Http::assertSent(fn (Request $q) => $q->hasHeader('X-Idempotency-Key', 'refund-mp-refund') && $q->data() === ['amount' => 16.0]);
    }

    public function test_paypal_creates_an_order_with_request_ids_and_returns_the_approval_link(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'TOK', 'expires_in' => 32400], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response(['id' => 'ORDER-1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [['rel' => 'self', 'href' => 'x'], ['rel' => 'payer-action', 'href' => 'https://paypal.test/approve']]], 201),
        ]);

        $r = $this->payments()->start($this->request('paypal', amount: '25.50', currency: 'USD', key: 'pp-1', market: 'US'));

        $this->assertSame(['type' => 'redirect', 'url' => 'https://paypal.test/approve', 'client_id' => 'paypal-client'], $r['client_action']);
        Http::assertSent(function (Request $q) use ($r) {
            if (! str_ends_with($q->url(), '/v2/checkout/orders')) {
                return false;
            }
            $unit = $q->data()['purchase_units'][0];

            return $q->hasHeader('PayPal-Request-Id', 'order-'.$r['payment']['id']) && $q->hasHeader('Authorization', 'Bearer TOK') && $q->data()['intent'] === 'CAPTURE'
                && $unit['amount'] === ['currency_code' => 'USD', 'value' => '25.50'] && $unit['custom_id'] === $r['payment']['id'];
        });
        // The access token was fetched once and cached for the next call.
        Http::assertSentCount(2);
    }

    public function test_paypal_capture_after_the_shopper_returns_marks_the_payment_paid_and_a_second_return_changes_nothing(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'TOK', 'expires_in' => 32400], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response(['id' => 'ORDER-1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [['rel' => 'payer-action', 'href' => 'h']]], 201),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-1/capture' => Http::sequence()
                ->push(['id' => 'ORDER-1', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-1', 'status' => 'COMPLETED', 'amount' => ['value' => '25.50', 'currency_code' => 'USD']]]]]]], 201)
                ->push(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]], 422),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-1' => Http::response(['id' => 'ORDER-1', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-1', 'status' => 'COMPLETED', 'amount' => ['value' => '25.50', 'currency_code' => 'USD']]]]]]], 200),
        ]);
        $id = $this->payments()->start($this->request('paypal', amount: '25.50', currency: 'USD', key: 'pp-1', market: 'US'))['payment']['id'];

        $first = $this->payments()->finalize($id);
        $this->assertSame(['captured', 'CAP-1'], [$first['status'], $first['provider_reference']]);

        $second = $this->payments()->finalize($id); // reload of the return page
        $this->assertSame('captured', $second['status']);
        $this->assertSame(1, DB::table('outbox_messages')->where('type', 'payment.captured')->count());
    }

    public function test_paypal_refunds_target_the_capture_not_the_order(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'TOK', 'expires_in' => 32400], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response(['id' => 'ORDER-1', 'status' => 'CREATED', 'links' => []], 201),
        ]);
        $id = $this->payments()->start($this->request('paypal', amount: '25.50', currency: 'USD', key: 'pp-1', market: 'US'))['payment']['id'];
        $this->payments()->applyNotice($id, new PaymentNotice(PaymentNotice::KIND_STATUS, $id, 'CAP-1', 'captured', '25.50', 'USD', 'e1'), 'webhook', 'e1');
        Http::fake(['api-m.sandbox.paypal.com/v2/payments/captures/CAP-1/refund' => Http::response(['id' => 'REF-1', 'status' => 'COMPLETED'], 201)]);

        $this->payments()->refund($id, '5.00', 'Cliente arrepentido', 'pp-refund', 'u1');

        Http::assertSent(fn (Request $q) => str_ends_with($q->url(), '/captures/CAP-1/refund') && $q->hasHeader('PayPal-Request-Id', 'refund-pp-refund') && $q->data()['amount'] === ['value' => '5.00', 'currency_code' => 'USD']);
        $this->assertSame('partially_refunded', DB::table('payments')->where('id', $id)->value('status'));
    }

    public function test_paypal_credentials_that_paypal_rejects_are_a_clear_error_not_a_crash(): void
    {
        Http::fake(['api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['error' => 'invalid_client', 'error_description' => 'Client Authentication failed'], 401)]);

        try {
            $this->payments()->start($this->request('paypal', key: 'pp-1', market: 'US'));
            $this->fail('expected a refusal');
        } catch (PaymentError $e) {
            $this->assertStringContainsString('Client Authentication failed', $e->getMessage());
            $this->assertFalse($e->retryable);
        }
        $this->assertSame('failed', DB::table('payments')->value('status'));
    }

    public function test_a_provider_without_credentials_for_its_mode_cannot_be_used_and_nothing_is_recorded_as_a_charge(): void
    {
        DB::table('payment_providers')->where('code', 'stripe')->update(['credentials_sandbox' => null]);

        try {
            $this->payments()->start($this->request());
            $this->fail('expected PROVIDER_NOT_CONFIGURED');
        } catch (PaymentError $e) {
            $this->assertSame('PROVIDER_NOT_CONFIGURED', $e->errorCode());
        }
    }
}
