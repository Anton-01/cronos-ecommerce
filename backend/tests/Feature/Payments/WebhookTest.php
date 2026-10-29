<?php

namespace Tests\Feature\Payments;

use Cronos\Payments\Application\PaymentReconciler;
use Cronos\Payments\Application\WebhookProcessor;
use Cronos\Payments\Infrastructure\Jobs\ProcessWebhookJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

final class WebhookTest extends PaymentsTestCase
{
    private function startStripe(): string
    {
        $this->fakeStripeCreate();

        return $this->payments()->start($this->request())['payment']['id'];
    }

    private function deliver(string $provider, string $body, array $headers, string $query = ''): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '54.187.174.169'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', "/webhooks/{$provider}".($query !== '' ? '?'.$query : ''), [], [], [], $server, $body);
    }

    private function succeeded(string $paymentId, string $eventId = 'evt_ok', int $amount = 11600): string
    {
        return $this->stripeEvent($eventId, 'payment_intent.succeeded', $this->stripeIntent('succeeded', 'pi_1', ['amount_received' => $amount, 'metadata' => ['payment_id' => $paymentId]]));
    }

    public function test_a_signed_stripe_notification_is_stored_then_processed_from_the_queue_and_captures_the_payment(): void
    {
        $id = $this->startStripe();
        Queue::fake();
        $body = $this->succeeded($id);

        $this->deliver('stripe', $body, $this->stripeHeaders($body))->assertOk()->assertJson(['received' => true]);

        $event = DB::table('webhook_events')->first();
        $this->assertSame(['stripe', 'sandbox', 'evt_ok', 'payment_intent.succeeded', true, 'received'], [$event->provider, $event->mode, $event->event_id, $event->event_type, (bool) $event->signature_valid, $event->status]);
        $this->assertSame($body, $event->raw_body, 'the exact bytes are kept, for re-verification and forensics');
        Queue::assertPushedOn('payments', ProcessWebhookJob::class, fn (ProcessWebhookJob $j) => $j->eventId === $event->id);
        $this->assertSame('requires_action', DB::table('payments')->where('id', $id)->value('status'), 'nothing is processed in the request');

        $this->assertSame('processed', app(WebhookProcessor::class)->process($event->id));
        $this->assertSame('captured', DB::table('payments')->where('id', $id)->value('status'));
        $this->assertSame($id, DB::table('webhook_events')->value('payment_id'));
    }

    public function test_redelivery_of_the_same_event_is_acknowledged_and_does_nothing_more(): void
    {
        $id = $this->startStripe();
        Queue::fake();
        $body = $this->succeeded($id);

        $this->deliver('stripe', $body, $this->stripeHeaders($body))->assertOk();
        $this->deliver('stripe', $body, $this->stripeHeaders($body))->assertOk();

        $this->assertSame(1, DB::table('webhook_events')->count());
        Queue::assertPushed(ProcessWebhookJob::class, 1);

        $event = DB::table('webhook_events')->value('id');
        $processor = app(WebhookProcessor::class);
        $this->assertSame('processed', $processor->process($event));
        $this->assertSame('skipped', $processor->process($event), 'an event that is done is never processed again');
        $this->assertSame(1, DB::table('outbox_messages')->where('type', 'payment.captured')->count());
    }

    public function test_a_bad_signature_is_refused_kept_for_forensics_and_never_processed(): void
    {
        $id = $this->startStripe();
        Queue::fake();
        $body = $this->succeeded($id);

        $this->deliver('stripe', $body, $this->stripeHeaders($body, 'whsec_forged'))->assertStatus(400)->assertJson(['error' => 'INVALID_SIGNATURE']);
        $this->deliver('stripe', $body, $this->stripeHeaders($body, 'whsec_forged'))->assertStatus(400);
        $this->deliver('stripe', $body, [])->assertStatus(400);

        $rows = DB::table('webhook_events')->get();
        $this->assertSame(1, $rows->count(), 'the same forged body is stored once');
        $this->assertSame(['rejected', 0], [$rows[0]->status, (int) $rows[0]->signature_valid]);
        Queue::assertNothingPushed();
        $this->assertSame('requires_action', DB::table('payments')->where('id', $id)->value('status'));
    }

    public function test_a_correct_signature_that_is_too_old_cannot_be_replayed(): void
    {
        $id = $this->startStripe();
        $body = $this->succeeded($id);

        $this->deliver('stripe', $body, $this->stripeHeaders($body, null, time() - 3600))->assertStatus(400);
    }

    public function test_unknown_providers_and_oversized_bodies_are_turned_away(): void
    {
        $this->deliver('square', '{}', [])->assertNotFound();
        $this->deliver('stripe', str_repeat('x', 300000), [])->assertStatus(413);
    }

    public function test_a_notification_that_outruns_our_bookkeeping_is_retried_until_the_payment_appears(): void
    {
        Queue::fake();
        $body = $this->succeeded('01hnotyetcreatedpayment00');
        $this->deliver('stripe', $body, $this->stripeHeaders($body))->assertOk();
        $event = DB::table('webhook_events')->value('id');
        $processor = app(WebhookProcessor::class);

        $this->assertSame('retry', $processor->process($event));
        $row = DB::table('webhook_events')->first();
        $this->assertSame(['failed', 1], [$row->status, (int) $row->attempts]);
        $this->assertNotNull($row->next_attempt_at);
        $this->assertStringContainsString('payment of this notification', (string) $row->last_error);

        // The payment is created a moment later (same id the provider already knows from our metadata).
        DB::table('payments')->insert(['id' => '01hnotyetcreatedpayment00', 'provider' => 'stripe', 'mode' => 'sandbox', 'payable_type' => 'checkout', 'payable_id' => 'c', 'amount' => '116.00', 'currency' => 'MXN', 'idempotency_key' => 'late', 'status' => 'requires_action', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame('processed', $processor->process($event));
        $this->assertSame('captured', DB::table('payments')->where('id', '01hnotyetcreatedpayment00')->value('status'));
    }

    public function test_an_event_for_a_payment_that_never_shows_up_is_ignored_after_the_last_retry_not_reported_as_an_incident(): void
    {
        Queue::fake();
        $body = $this->succeeded('01hghost');
        $this->deliver('stripe', $body, $this->stripeHeaders($body));
        $event = DB::table('webhook_events')->value('id');
        $processor = app(WebhookProcessor::class);

        $outcomes = [];
        for ($i = 0; $i < count(WebhookProcessor::BACKOFF); $i++) {
            $outcomes[] = $processor->process($event);
        }

        $this->assertSame(['retry', 'ignored'], [$outcomes[0], end($outcomes)]);
        $this->assertSame('skipped', $processor->process($event), 'finished');
        $this->assertSame('ignored', DB::table('webhook_events')->value('status'));
    }

    public function test_a_provider_error_while_interpreting_keeps_the_event_for_a_later_sweep_which_then_completes_it(): void
    {
        // Mercado Pago sends only an id; the facts come from its API, which is down at first.
        $id = $this->mpPayment();
        $body = json_encode(['id' => 777, 'type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => '5551']]);
        Http::fake(['api.mercadopago.com/v1/payments/5551' => Http::sequence()->push([], 500)->push([], 500)->push([], 500)->push([], 500)->push($this->mpApproved($id), 200)]);

        $this->deliver('mercadopago', $body, $this->mpHeaders('5551'), 'data.id=5551&type=payment')->assertOk();
        $event = DB::table('webhook_events')->value('id');
        $this->assertSame('failed', DB::table('webhook_events')->where('id', $event)->value('status'), 'inline (sync) processing hit the outage');
        $this->assertSame('requires_action', DB::table('payments')->where('id', $id)->value('status'));

        DB::table('webhook_events')->where('id', $event)->update(['next_attempt_at' => now()->subMinute()]);
        $this->assertSame(1, app(PaymentReconciler::class)->sweepWebhooks());

        $p = DB::table('payments')->where('id', $id)->first();
        $this->assertSame(['captured', '5551'], [$p->status, $p->provider_reference], 'MP\'s payment id replaced the preference id');
        $this->assertSame('processed', DB::table('webhook_events')->where('id', $event)->value('status'));
    }

    public function test_a_worker_that_died_mid_event_does_not_strand_it(): void
    {
        $id = $this->startStripe();
        $body = $this->succeeded($id);
        $this->deliver('stripe', $body, $this->stripeHeaders($body));
        $event = DB::table('webhook_events')->value('id');
        DB::table('webhook_events')->where('id', $event)->update(['status' => 'processing', 'attempts' => 1, 'next_attempt_at' => now()->subMinute()]); // lease expired

        $this->assertSame(1, app(PaymentReconciler::class)->sweepWebhooks());
        $this->assertSame('captured', DB::table('payments')->where('id', $id)->value('status'));
    }

    public function test_a_dead_event_can_be_put_back_by_a_person_and_then_succeeds(): void
    {
        $id = $this->startStripe();
        $body = $this->succeeded($id);
        $this->deliver('stripe', $body, $this->stripeHeaders($body));
        $event = DB::table('webhook_events')->value('id');
        DB::table('webhook_events')->where('id', $event)->update(['status' => 'dead', 'attempts' => 8, 'last_error' => 'boom']);
        $processor = app(WebhookProcessor::class);

        $this->assertSame('skipped', $processor->process($event), 'dead events wait for a person');
        $processor->reset($event);
        $row = DB::table('webhook_events')->first();
        $this->assertSame(['received', 0, null], [$row->status, (int) $row->attempts, $row->last_error]);

        $this->assertSame('processed', $processor->process($event));
        $this->assertSame('captured', DB::table('payments')->where('id', $id)->value('status'));
    }

    public function test_a_sandbox_payment_can_never_be_changed_by_a_live_notification(): void
    {
        $id = $this->startStripe();
        $this->providers()->saveCredentials('stripe', 'live', ['secret_key' => 'sk_live_x', 'publishable_key' => 'pk_live_x', 'webhook_secret' => 'whsec_live']);
        $body = $this->succeeded($id);

        $this->deliver('stripe', $body, $this->stripeHeaders($body, 'whsec_live'))->assertOk();

        $event = DB::table('webhook_events')->first();
        $this->assertSame(['live', 'dead'], [$event->mode, $event->status]);
        $this->assertStringContainsString('came from live but the payment is sandbox', (string) $event->last_error);
        $this->assertSame('requires_action', DB::table('payments')->where('id', $id)->value('status'));
    }

    public function test_mercado_pago_notifications_are_verified_with_the_signature_manifest_and_the_query_id(): void
    {
        $id = $this->mpPayment();
        Http::fake(['api.mercadopago.com/v1/payments/5551' => Http::response($this->mpApproved($id), 200)]);
        $body = json_encode(['id' => 1, 'type' => 'payment', 'action' => 'payment.created', 'data' => ['id' => '5551']]);

        $this->deliver('mercadopago', $body, $this->mpHeaders('5551', secret: 'wrong'), 'data.id=5551&type=payment')->assertStatus(400);
        $this->deliver('mercadopago', $body, $this->mpHeaders('9999'), 'data.id=5551&type=payment')->assertStatus(400); // signed for another payment id
        $this->deliver('mercadopago', $body, $this->mpHeaders('5551'), 'data.id=5551&type=payment')->assertOk();

        $this->assertSame('captured', DB::table('payments')->where('id', $id)->value('status'));
    }

    public function test_paypal_notifications_are_verified_by_paypal_in_the_queue_and_an_approval_triggers_the_capture(): void
    {
        $id = $this->paypalPayment();
        $body = json_encode(['id' => 'WH-EVT-1', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'ORDER-1', 'purchase_units' => [['custom_id' => $id]]]]);
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'TOK', 'expires_in' => 32400], 200),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-1/capture' => Http::response(['id' => 'ORDER-1', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-1', 'status' => 'COMPLETED', 'amount' => ['value' => '116.00', 'currency_code' => 'MXN']]]]]]], 201),
        ]);

        $this->deliver('paypal', $body, ['paypal-transmission-id' => 't1', 'paypal-transmission-time' => '2026-01-01T00:00:00Z', 'paypal-transmission-sig' => 'sig', 'paypal-cert-url' => 'https://api.paypal.com/cert', 'paypal-auth-algo' => 'SHA256withRSA'])->assertOk();

        $p = DB::table('payments')->where('id', $id)->first();
        $this->assertSame(['captured', 'CAP-1'], [$p->status, $p->provider_reference]);
        $this->assertSame('ORDER-1', json_decode($p->metadata, true)['initial_reference'], 'the order id is kept next to the capture id');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/orders/ORDER-1/capture') && $r->hasHeader('PayPal-Request-Id', 'capture-'.$id));
        $this->assertTrue((bool) DB::table('webhook_events')->value('signature_valid'));
    }

    public function test_a_paypal_notification_that_paypal_does_not_vouch_for_is_never_applied(): void
    {
        $id = $this->paypalPayment();
        $body = json_encode(['id' => 'WH-FORGED', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'CAP-9', 'custom_id' => $id, 'amount' => ['value' => '116.00', 'currency_code' => 'MXN']]]);
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'TOK', 'expires_in' => 32400], 200),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE'], 200),
        ]);

        $this->deliver('paypal', $body, ['paypal-transmission-sig' => 'forged', 'paypal-transmission-id' => 'x', 'paypal-transmission-time' => 'x', 'paypal-cert-url' => 'x', 'paypal-auth-algo' => 'x'])->assertOk();

        $this->assertSame('requires_action', DB::table('payments')->where('id', $id)->value('status'));
        $event = DB::table('webhook_events')->first();
        $this->assertSame(['dead', 0], [$event->status, (int) $event->signature_valid]);
        $this->assertStringContainsString('signature is not valid', (string) $event->last_error);
    }

    // --- helpers for the Mercado Pago / PayPal payments -------------------------------------------------------------------

    private function mpPayment(): string
    {
        Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response(['id' => 'PREF-1', 'init_point' => 'https://mp.test/pay', 'sandbox_init_point' => 'https://sandbox.mp.test/pay'], 201)]);

        return $this->payments()->start($this->request('mercadopago', key: 'mp-1'))['payment']['id'];
    }

    private function mpApproved(string $paymentId): array
    {
        return ['id' => 5551, 'status' => 'approved', 'transaction_amount' => 116, 'currency_id' => 'MXN', 'external_reference' => $paymentId, 'transaction_amount_refunded' => 0];
    }

    /** @return array<string, string> */
    private function mpHeaders(string $signedDataId, string $secret = 'mp_secret'): array
    {
        $ts = (string) (time() * 1000);
        $requestId = 'req-'.uniqid();
        $manifest = 'id:'.$signedDataId.';request-id:'.$requestId.';ts:'.$ts.';';

        return ['x-signature' => "ts={$ts},v1=".hash_hmac('sha256', $manifest, $secret), 'x-request-id' => $requestId];
    }

    private function paypalPayment(): string
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'TOK', 'expires_in' => 32400], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response(['id' => 'ORDER-1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [['rel' => 'payer-action', 'href' => 'https://paypal.test/approve']]], 201),
        ]);

        return $this->payments()->start($this->request('paypal', amount: '116.00', currency: 'MXN', key: 'pp-1', market: 'US'))['payment']['id'];
    }
}
