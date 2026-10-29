<?php

namespace Tests\Feature\Payments;

use Cronos\Payments\Application\OutboxRelay;
use Cronos\Payments\Application\PaymentReconciler;
use Cronos\Payments\Domain\Events\PaymentCaptured;
use Cronos\Payments\Domain\Exceptions\GatewayUnavailable;
use Cronos\Payments\Domain\Exceptions\PaymentError;
use Cronos\Payments\Domain\ValueObjects\PaymentNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

final class RefundAndRecoveryTest extends PaymentsTestCase
{
    private function paid(): string
    {
        $this->fakeStripeCreate();
        $id = $this->payments()->start($this->request())['payment']['id'];
        $this->payments()->applyNotice($id, new PaymentNotice(PaymentNotice::KIND_STATUS, $id, 'pi_1', 'captured', '116.00', 'MXN', 'evt_paid'), 'webhook', 'evt_paid');

        return $id;
    }

    public function test_a_partial_then_full_refund_moves_the_payment_through_its_states_and_never_exceeds_what_was_paid(): void
    {
        $id = $this->paid();
        Http::fake(['api.stripe.com/v1/refunds' => Http::sequence()->push(['id' => 're_1', 'status' => 'succeeded'], 200)->push(['id' => 're_2', 'status' => 'succeeded'], 200)]);

        $r1 = $this->payments()->refund($id, '40.00', 'Producto dañado', 'refund-a', 'user-1');
        $this->assertSame(['succeeded', 're_1'], [$r1['status'], $r1['provider_reference']]);
        $this->assertSame(['partially_refunded', '40.00'], [DB::table('payments')->where('id', $id)->value('status'), (string) DB::table('payments')->where('id', $id)->value('refunded_amount')]);

        try {
            $this->payments()->refund($id, '100.00', null, 'refund-b', 'user-1');
            $this->fail('expected the refund to be refused');
        } catch (PaymentError $e) {
            $this->assertSame('REFUND_EXCEEDS_PAYMENT', $e->errorCode());
            $this->assertStringContainsString('76.00', $e->getMessage());
        }

        $this->payments()->refund($id, '76.00', null, 'refund-c', 'user-1');
        $this->assertSame(['refunded', '116.00'], [DB::table('payments')->where('id', $id)->value('status'), (string) DB::table('payments')->where('id', $id)->value('refunded_amount')]);
        $this->assertSame(2, DB::table('outbox_messages')->where('type', 'payment.refunded')->count());
    }

    public function test_repeating_a_refund_with_the_same_key_returns_the_same_refund_and_does_not_call_the_provider_again(): void
    {
        $id = $this->paid();
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded'], 200)]);

        $a = $this->payments()->refund($id, '40.00', null, 'same-key', 'user-1');
        $b = $this->payments()->refund($id, '40.00', null, 'same-key', 'user-1');

        $this->assertSame($a['id'], $b['id']);
        Http::assertSentCount(1); // a single refund call for the two requests
        $this->assertSame('40.00', (string) DB::table('payments')->where('id', $id)->value('refunded_amount'));

        $this->expectException(PaymentError::class);
        $this->payments()->refund($id, '41.00', null, 'same-key', 'user-1'); // same key, different amount
    }

    public function test_a_refund_whose_provider_call_timed_out_stays_pending_and_the_same_key_finishes_it_without_refunding_twice(): void
    {
        $id = $this->paid();
        Http::fake(['api.stripe.com/v1/refunds' => Http::sequence()->push([], 503)->push([], 503)->push([], 503)->push([], 503)->push(['id' => 're_9', 'status' => 'succeeded'], 200)]);

        try {
            $this->payments()->refund($id, '20.00', null, 'retry-me', 'user-1');
            $this->fail('expected the provider to be unreachable');
        } catch (GatewayUnavailable) {
        }
        $this->assertSame('pending', DB::table('refunds')->value('status'));
        $this->assertSame('0.00', (string) DB::table('payments')->where('id', $id)->value('refunded_amount'));
        // while it is pending the money is already committed: the remaining balance accounts for it
        try {
            $this->payments()->refund($id, '100.00', null, 'second', 'user-1');
            $this->fail('expected REFUND_EXCEEDS_PAYMENT');
        } catch (PaymentError $e) {
            $this->assertSame('REFUND_EXCEEDS_PAYMENT', $e->errorCode());
        }

        $done = $this->payments()->refund($id, '20.00', null, 'retry-me', 'user-1');

        $this->assertSame(['succeeded', 're_9'], [$done['status'], $done['provider_reference']]);
        $this->assertSame(1, DB::table('refunds')->count());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/refunds') && $r->hasHeader('Idempotency-Key', 'refund-retry-me'));
    }

    public function test_the_providers_own_refund_notification_after_ours_is_not_counted_twice_and_an_outside_refund_is_adopted(): void
    {
        $id = $this->paid();
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded'], 200)]);
        $this->payments()->refund($id, '40.00', null, 'k', 'user-1');

        // Stripe tells us the charge now has 40.00 refunded (absolute figure) — the same fact.
        $this->payments()->applyNotice($id, new PaymentNotice(PaymentNotice::KIND_REFUND, $id, 'pi_1', refundTotal: '40.00', eventId: 'evt_r1'), 'webhook', 'evt_r1');
        $this->assertSame('40.00', (string) DB::table('payments')->where('id', $id)->value('refunded_amount'));

        // Someone refunds 30 more in the Stripe dashboard.
        $this->payments()->applyNotice($id, new PaymentNotice(PaymentNotice::KIND_REFUND, $id, 'pi_1', amount: '30.00', refundReference: 're_dash', eventId: 'evt_r2'), 'webhook', 'evt_r2');
        $this->payments()->applyNotice($id, new PaymentNotice(PaymentNotice::KIND_REFUND, $id, 'pi_1', amount: '30.00', refundReference: 're_dash', eventId: 'evt_r2b'), 'webhook', 'evt_r2b'); // same refund, another delivery

        $this->assertSame(['partially_refunded', '70.00'], [DB::table('payments')->where('id', $id)->value('status'), (string) DB::table('payments')->where('id', $id)->value('refunded_amount')]);
        $this->assertSame('Issued outside the shop (provider dashboard)', DB::table('refunds')->where('provider_reference', 're_dash')->value('reason'));
    }

    public function test_only_collected_money_can_be_refunded(): void
    {
        $this->fakeStripeCreate();
        $id = $this->payments()->start($this->request())['payment']['id'];

        try {
            $this->payments()->refund($id, '10.00', null, 'k', null);
            $this->fail('expected NOT_REFUNDABLE');
        } catch (PaymentError $e) {
            $this->assertSame('NOT_REFUNDABLE', $e->errorCode());
        }
        $this->assertSame(0, DB::table('refunds')->count());
    }

    public function test_a_dispute_is_recorded_and_flagged_and_winning_it_restores_the_payment(): void
    {
        $id = $this->paid();

        $this->payments()->applyNotice($id, new PaymentNotice(PaymentNotice::KIND_DISPUTE, null, 'pi_1', eventId: 'evt_d1'), 'webhook', 'evt_d1');
        $this->assertSame('disputed', DB::table('payments')->where('id', $id)->value('status'));
        $this->assertSame(1, DB::table('outbox_messages')->where('type', 'payment.needs_review')->count());

        $this->payments()->applyNotice($id, new PaymentNotice(PaymentNotice::KIND_DISPUTE, null, 'pi_1', eventId: 'evt_d2', disputeWon: true), 'webhook', 'evt_d2');
        $this->assertSame('captured', DB::table('payments')->where('id', $id)->value('status'));
    }

    public function test_the_reconciler_finds_payments_whose_webhook_never_came_and_abandons_ones_the_provider_never_heard_of(): void
    {
        $this->fakeStripeCreate();
        $lost = $this->payments()->start($this->request(key: 'lost'))['payment']['id'];
        Http::fake(['api.stripe.com/v1/payment_intents/pi_1*' => Http::response($this->stripeIntent('succeeded', 'pi_1', ['amount_received' => 11600, 'metadata' => ['payment_id' => $lost]]), 200)]);
        $neverCreated = DB::table('payments')->insertGetId(['id' => '01hneverreachedtheprovider', 'provider' => 'stripe', 'mode' => 'sandbox', 'payable_type' => 'checkout', 'payable_id' => 'x', 'amount' => '10.00', 'currency' => 'MXN', 'idempotency_key' => 'ghost', 'status' => 'pending', 'metadata' => '{}', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()], 'id');
        DB::table('payments')->where('id', $lost)->update(['updated_at' => now()->subMinutes(10)]);

        $report = app(PaymentReconciler::class)->run();

        $this->assertSame(['checked' => 2, 'changed' => 1, 'abandoned' => 1, 'errors' => 0], $report);
        $this->assertSame('captured', DB::table('payments')->where('id', $lost)->value('status'));
        $this->assertSame('cancelled', DB::table('payments')->where('id', '01hneverreachedtheprovider')->value('status'));
        $this->assertNotNull($neverCreated);

        $again = app(PaymentReconciler::class)->run();
        $this->assertSame(0, $again['changed'], 'a second sweep finds nothing new');
    }

    public function test_the_reconciler_survives_a_provider_outage_and_tries_again_next_time(): void
    {
        $this->fakeStripeCreate();
        $id = $this->payments()->start($this->request())['payment']['id'];
        DB::table('payments')->where('id', $id)->update(['updated_at' => now()->subMinutes(10)]);
        Http::fake(['api.stripe.com/v1/payment_intents/pi_1*' => Http::response([], 500)]);

        $report = app(PaymentReconciler::class)->run();

        $this->assertSame(1, $report['errors']);
        $this->assertSame('requires_action', DB::table('payments')->where('id', $id)->value('status'));
        $this->assertSame('error', DB::table('payment_transactions')->where('type', 'sync')->value('status'));
    }

    public function test_the_outbox_delivers_each_message_after_the_commit_and_retries_a_failing_listener_without_losing_it(): void
    {
        Queue::fake();
        Event::fake([PaymentCaptured::class]);
        $this->paid();
        $this->assertSame('pending', DB::table('outbox_messages')->where('type', 'payment.captured')->value('status'), 'with the queue faked the relay has not run yet');

        $this->assertSame(1, app(OutboxRelay::class)->run());
        Event::assertDispatched(PaymentCaptured::class, fn (PaymentCaptured $e) => $e->amount === '116.00' && $e->provider === 'stripe' && $e->mode === 'sandbox' && $e->anomaly === null);
        $this->assertSame('dispatched', DB::table('outbox_messages')->where('type', 'payment.captured')->value('status'));
        $this->assertSame(0, app(OutboxRelay::class)->run(), 'delivered messages are not delivered again');
    }

    public function test_a_listener_that_throws_gets_the_message_again_later(): void
    {
        Queue::fake();
        $calls = 0;
        Event::listen(PaymentCaptured::class, function () use (&$calls) {
            if (++$calls === 1) {
                throw new \RuntimeException('order service down');
            }
        });
        $this->paid();

        app(OutboxRelay::class)->run();
        $row = DB::table('outbox_messages')->where('type', 'payment.captured')->first();
        $this->assertSame(['pending', 1], [$row->status, (int) $row->attempts]);
        $this->assertStringContainsString('order service down', (string) $row->last_error);

        DB::table('outbox_messages')->update(['available_at' => now()->subSecond()]);
        $this->assertSame(1, app(OutboxRelay::class)->run());
        $this->assertSame(2, $calls);
        $this->assertSame('dispatched', DB::table('outbox_messages')->where('type', 'payment.captured')->value('status'));
    }
}
