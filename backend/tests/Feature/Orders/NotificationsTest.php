<?php

namespace Tests\Feature\Orders;

use Cronos\Notifications\Application\Listeners\NotifyAboutOrders;
use Cronos\Notifications\Infrastructure\Jobs\SendNotificationJob;
use Cronos\Notifications\Infrastructure\Mail\ShopMail;
use Cronos\Payments\Application\OutboxRelay;
use Cronos\Payments\Domain\Events\PaymentNeedsReview;
use Cronos\Shipping\Application\ShipmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

final class NotificationsTest extends OrdersTestCase
{
    private function order(?string $userId = null): array
    {
        return $this->placeOrder($this->stocked($this->published($this->piece(price: '100.00')), 20), '2', $userId);
    }

    private function deliver(): void
    {
        // Queue is faked in this suite: run what was queued, the way a worker would.
        Queue::assertPushed(SendNotificationJob::class);
        foreach (DB::table('notification_log')->where('status', 'queued')->pluck('id') as $id) {
            app()->call([new SendNotificationJob($id), 'handle']);
        }
    }

    public function test_the_customer_is_told_once_in_their_own_language_when_the_order_is_placed(): void
    {
        Mail::fake();
        $order = $this->order();
        $this->assertSame(1, DB::table('notification_log')->where('template', 'order_placed')->count());
        $this->deliver();

        Mail::assertSent(ShopMail::class, function ($m) use ($order) {
            return $m->hasTo('ana@example.com') && str_contains($m->envelope()->subject, $order['order_number']) && str_starts_with($m->envelope()->subject, 'Recibimos tu pedido');
        });
        $this->assertSame('sent', DB::table('notification_log')->value('status'));

        // The outbox delivers at least once: a replay must not mail twice.
        DB::table('outbox_messages')->update(['dispatched_at' => null, 'attempts' => 0]);
        app(OutboxRelay::class)->run();
        $this->assertSame(1, DB::table('notification_log')->where('template', 'order_placed')->count());
    }

    public function test_shipping_and_delivery_and_cancellation_each_send_their_own_message_and_the_text_renders_in_both_languages(): void
    {
        Mail::fake();
        $us = $this->makeUser('bob@example.com');
        DB::table('user_profiles')->where('user_id', $us->id)->update(['locale' => 'en-US']);
        $order = $this->order($us->id);
        $this->shipments()->create($order['id'], 'dhl', null, 'D5', null, [], [], null, null, null);
        $this->shipments()->ingest('dhl', 'D5', 'd1', 'delivered', null, null, new \DateTimeImmutable, null);

        $templates = DB::table('notification_log')->orderBy('created_at')->pluck('template')->all();
        $this->assertSame(['order_placed', 'shipment_created', 'order_status'], $templates, 'shipped is announced by the shipment, not twice');
        $this->deliver();
        Mail::assertSent(ShopMail::class, fn ($m) => str_starts_with($m->envelope()->subject, 'Your order') && str_contains($m->envelope()->subject, 'delivered'));
        foreach (DB::table('notification_log')->get() as $row) {
            $mail = new ShopMail($row->template, $row->locale, json_decode($row->data, true));
            $html = $mail->render();
            $this->assertStringContainsString($order['order_number'], $mail->envelope()->subject);
            $this->assertNotEmpty($html);
            $this->assertStringNotContainsString('shop_mail.', $html, 'no missing translation keys');
        }
    }

    public function test_a_failed_delivery_is_recorded_and_the_sweep_requeues_what_was_never_sent(): void
    {
        $this->order();
        $id = DB::table('notification_log')->value('id');
        DB::table('notification_log')->where('id', $id)->update(['updated_at' => now()->subHour()]);

        $this->artisan('notifications:sweep')->assertSuccessful();
        Queue::assertPushedTimes(SendNotificationJob::class, 2);

        (new SendNotificationJob($id))->failed(new \RuntimeException('smtp down'));
        $this->assertSame(['failed', 'smtp down'], [DB::table('notification_log')->value('status'), DB::table('notification_log')->value('last_error')]);
    }

    public function test_payment_anomalies_alert_the_operator_when_an_address_is_configured(): void
    {
        config(['notifications.ops_email' => 'ops@shop.test']);
        app(NotifyAboutOrders::class)->paymentNeedsReview(new PaymentNeedsReview('pay-1', 'checkout', 'chk-1', 'late_capture'));
        app(NotifyAboutOrders::class)->paymentNeedsReview(new PaymentNeedsReview('pay-1', 'checkout', 'chk-1', 'late_capture'));

        $this->assertSame(1, DB::table('notification_log')->where(['template' => 'ops_alert', 'email' => 'ops@shop.test'])->count());
    }

    private function shipments(): ShipmentService
    {
        return app(ShipmentService::class);
    }
}
