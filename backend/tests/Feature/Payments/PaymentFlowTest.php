<?php

namespace Tests\Feature\Payments;

use Cronos\Payments\Application\ProviderManager;
use Cronos\Payments\Domain\Exceptions\GatewayUnavailable;
use Cronos\Payments\Domain\Exceptions\PaymentError;
use Cronos\Payments\Domain\ValueObjects\PaymentNotice;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class PaymentFlowTest extends PaymentsTestCase
{
    private function captured(string $paymentId, string $amount = '116.00', string $event = 'evt_1'): void
    {
        $this->payments()->applyNotice($paymentId, new PaymentNotice(PaymentNotice::KIND_STATUS, $paymentId, 'pi_1', 'captured', $amount, 'MXN', $event), 'webhook', $event);
    }

    public function test_starting_a_payment_creates_the_provider_intent_with_an_idempotency_key_and_returns_the_client_secret(): void
    {
        $this->fakeStripeCreate();

        $r = $this->payments()->start($this->request());

        $this->assertSame(['requires_action', 'pi_1', 'stripe', 'sandbox'], [$r['payment']['status'], $r['payment']['provider_reference'], $r['payment']['provider'], $r['payment']['mode']]);
        $this->assertSame(['type' => 'client_secret', 'client_secret' => 'pi_1_secret_xyz', 'publishable_key' => 'pk_test_abc123'], $r['client_action']);
        $this->assertSame(['publishable_key' => 'pk_test_abc123'], $r['public']);

        Http::assertSent(function (Request $q) use ($r) {
            parse_str($q->body(), $form);

            return $q->url() === 'https://api.stripe.com/v1/payment_intents'
                && $q->hasHeader('Idempotency-Key', 'pi-'.$r['payment']['id'])
                && $q->hasHeader('Authorization', 'Bearer sk_test_abc123')
                && ($form['amount'] ?? null) === '11600' && ($form['currency'] ?? null) === 'mxn' && ($form['metadata']['payment_id'] ?? null) === $r['payment']['id'];
        });
        $this->assertSame('create', DB::table('payment_transactions')->value('type'));
    }

    public function test_the_client_secret_is_stored_encrypted_and_never_in_the_ledger(): void
    {
        $this->fakeStripeCreate();
        $r = $this->payments()->start($this->request());

        $this->assertStringNotContainsString('pi_1_secret_xyz', (string) DB::table('payments')->value('client_action'));
        $this->assertStringNotContainsString('pi_1_secret_xyz', (string) DB::table('payment_transactions')->value('raw_response'));
        $this->assertSame('[redacted]', json_decode((string) DB::table('payment_transactions')->value('raw_response'), true)['client_secret']);
    }

    public function test_the_same_idempotency_key_never_creates_two_payments_or_two_intents(): void
    {
        $this->fakeStripeCreate();

        $a = $this->payments()->start($this->request());
        $b = $this->payments()->start($this->request());

        $this->assertSame($a['payment']['id'], $b['payment']['id']);
        $this->assertSame(1, DB::table('payments')->count());
        Http::assertSentCount(1);
        $this->assertSame($a['client_action'], $b['client_action'], 'the shopper can come back and still get the same client secret');
    }

    public function test_a_key_reused_for_something_else_is_refused(): void
    {
        $this->fakeStripeCreate();
        $this->payments()->start($this->request());

        foreach ([$this->request(amount: '500.00'), $this->request(payable: 'chk-OTHER')] as $other) {
            try {
                $this->payments()->start($other);
                $this->fail('expected IDEMPOTENCY_MISMATCH');
            } catch (PaymentError $e) {
                $this->assertSame('IDEMPOTENCY_MISMATCH', $e->errorCode());
            }
        }
    }

    public function test_a_provider_that_does_not_answer_leaves_a_pending_payment_that_the_same_key_resumes_without_a_second_charge(): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::sequence()
            ->push(['error' => ['message' => 'upstream']], 503)->push(['error' => ['message' => 'upstream']], 503)->push(['error' => ['message' => 'upstream']], 503)->push(['error' => ['message' => 'upstream']], 503)
            ->push($this->stripeIntent(), 200)]);

        try {
            $this->payments()->start($this->request());
            $this->fail('expected the provider to be unreachable');
        } catch (GatewayUnavailable $e) {
            $this->assertTrue($e->retryable);
            $this->assertSame('GATEWAY_UNAVAILABLE', $e->errorCode());
        }
        $pending = DB::table('payments')->first();
        $this->assertSame(['pending', null], [$pending->status, $pending->provider_reference]);
        $this->assertSame(['create', 'error'], [DB::table('payment_transactions')->value('type'), DB::table('payment_transactions')->value('status')]);

        $resumed = $this->payments()->start($this->request()); // the shopper pressed "pay" again

        $this->assertSame([$pending->id, 'requires_action', 'pi_1'], [$resumed['payment']['id'], $resumed['payment']['status'], $resumed['payment']['provider_reference']]);
        $this->assertSame(1, DB::table('payments')->count());
        Http::assertSent(fn (Request $q) => $q->hasHeader('Idempotency-Key', 'pi-'.$pending->id));
    }

    public function test_transient_errors_are_retried_inside_one_call_with_the_same_idempotency_key(): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::sequence()->push([], 502)->push(['error' => ['message' => 'slow']], 429)->push($this->stripeIntent(), 200)]);

        $r = $this->payments()->start($this->request());

        $this->assertSame('pi_1', $r['payment']['provider_reference']);
        $keys = [];
        Http::assertSentCount(3);
        Http::assertSent(function (Request $q) use (&$keys) {
            $keys[] = $q->header('Idempotency-Key')[0] ?? null;

            return true;
        });
        $this->assertCount(1, array_unique($keys), 'all three attempts carried the same key, so Stripe would have charged once');
    }

    public function test_a_definite_refusal_fails_the_payment_and_is_not_retried(): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['error' => ['message' => 'Invalid currency', 'code' => 'parameter_invalid']], 400)]);

        try {
            $this->payments()->start($this->request());
            $this->fail('expected a refusal');
        } catch (PaymentError $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('Invalid currency', $e->getMessage());
        }

        Http::assertSentCount(1);
        $row = DB::table('payments')->first();
        $this->assertSame(['failed', 'PARAMETER_INVALID'], [$row->status, $row->failure_code]);
        $this->assertSame('payment.failed', DB::table('outbox_messages')->value('type'));
    }

    public function test_after_repeated_provider_failures_the_circuit_opens_and_calls_stop_without_hitting_the_network(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response([], 500)]);

        foreach (range(1, 2) as $i) {
            try {
                $this->payments()->start($this->request(key: "k{$i}"));
            } catch (GatewayUnavailable) {
            }
        }
        $sent = count(Http::recorded());
        $this->assertGreaterThanOrEqual(5, $sent);

        try {
            $this->payments()->start($this->request(key: 'k3'));
            $this->fail('expected the open circuit to refuse');
        } catch (GatewayUnavailable $e) {
            $this->assertStringContainsString('circuit open', $e->getMessage());
        }
        $this->assertCount($sent, Http::recorded(), 'an open circuit makes no request');
    }

    public function test_availability_rules_are_enforced_before_any_call(): void
    {
        $manager = app(ProviderManager::class);
        $manager->configure('stripe', ['min_amount' => '50', 'max_amount' => '1000']);
        Http::fake();

        foreach ([
            [fn () => $this->payments()->start($this->request(amount: '10.00')), 'AMOUNT_BELOW_MIN'],
            [fn () => $this->payments()->start($this->request(amount: '5000.00', key: 'k2')), 'AMOUNT_ABOVE_MAX'],
            [fn () => $this->payments()->start($this->request('paypal', key: 'k3', market: 'MX')), 'PROVIDER_NOT_AVAILABLE'],
        ] as [$attempt, $code]) {
            try {
                $attempt();
                $this->fail("expected {$code}");
            } catch (PaymentError $e) {
                $this->assertSame($code, $e->errorCode());
            }
        }
        $manager->configure('stripe', ['is_enabled' => false]);
        try {
            $this->payments()->start($this->request(key: 'k4'));
            $this->fail('expected PROVIDER_DISABLED');
        } catch (PaymentError $e) {
            $this->assertSame('PROVIDER_DISABLED', $e->errorCode());
        }
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_capturing_writes_the_ledger_and_the_outbox_in_the_same_step_and_replays_change_nothing(): void
    {
        $this->fakeStripeCreate();
        $id = $this->payments()->start($this->request())['payment']['id'];

        $this->captured($id);
        $this->captured($id); // the provider delivered it twice

        $p = DB::table('payments')->where('id', $id)->first();
        $this->assertSame(['captured', '116.00'], [$p->status, (string) $p->captured_amount]);
        $this->assertSame(1, DB::table('outbox_messages')->where('type', 'payment.captured')->count(), 'one announcement, however many deliveries');
        $this->assertSame(1, DB::table('payment_transactions')->where('type', 'webhook')->count());
    }

    public function test_an_out_of_order_event_cannot_undo_a_capture(): void
    {
        $this->fakeStripeCreate();
        $id = $this->payments()->start($this->request())['payment']['id'];
        $this->captured($id);

        $this->payments()->applyNotice($id, new PaymentNotice(PaymentNotice::KIND_STATUS, $id, 'pi_1', 'processing', eventId: 'evt_old'), 'webhook', 'evt_old');
        $this->payments()->applyNotice($id, new PaymentNotice(PaymentNotice::KIND_STATUS, $id, 'pi_1', 'failed', eventId: 'evt_older', failureCode: 'x'), 'webhook', 'evt_older');

        $this->assertSame('captured', DB::table('payments')->where('id', $id)->value('status'));
    }

    public function test_a_different_amount_is_never_accepted_as_paid(): void
    {
        $this->fakeStripeCreate();
        $id = $this->payments()->start($this->request())['payment']['id'];

        $this->captured($id, amount: '1.00');

        $p = DB::table('payments')->where('id', $id)->first();
        $this->assertSame(['requires_action', 'amount_mismatch', '0.00'], [$p->status, $p->anomaly, (string) $p->captured_amount]);
        $this->assertSame(0, DB::table('outbox_messages')->where('type', 'payment.captured')->count());
        $this->assertSame(1, DB::table('outbox_messages')->where('type', 'payment.needs_review')->count());
    }

    public function test_money_that_arrives_after_the_shop_gave_up_is_kept_and_flagged_for_a_person(): void
    {
        $this->fakeStripeCreate();
        $id = $this->payments()->start($this->request())['payment']['id'];
        $this->payments()->cancel($id, 'Checkout expired');
        $this->assertSame('cancelled', DB::table('payments')->where('id', $id)->value('status'));

        $this->captured($id);

        $p = DB::table('payments')->where('id', $id)->first();
        $this->assertSame(['captured', 'late_capture'], [$p->status, $p->anomaly]);
        $this->assertSame(1, DB::table('outbox_messages')->where('type', 'payment.captured')->count());
        $this->assertSame(1, DB::table('outbox_messages')->where('type', 'payment.needs_review')->count());
    }

    public function test_the_ledger_cannot_be_edited_or_erased(): void
    {
        $this->fakeStripeCreate();
        $this->payments()->start($this->request());

        foreach ([
            fn () => DB::table('payment_transactions')->update(['status' => 'captured']),
            fn () => DB::table('payment_transactions')->delete(),
            fn () => DB::statement('TRUNCATE payment_transactions'),
        ] as $tamper) {
            try {
                DB::transaction($tamper);
                $this->fail('the ledger accepted a change');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_database_refuses_nonsense_amounts_and_statuses(): void
    {
        $this->fakeStripeCreate();
        $id = $this->payments()->start($this->request())['payment']['id'];

        foreach ([['status' => 'paid'], ['amount' => 0], ['refunded_amount' => 5]] as $bad) {
            try {
                DB::table('payments')->where('id', $id)->update($bad);
                $this->fail('the payments table accepted '.json_encode($bad));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
