<?php

namespace Tests\Feature\Checkout;

use Cronos\Checkout\Application\CartService;
use Cronos\Checkout\Application\CheckoutService;
use Cronos\Checkout\Domain\Events\CheckoutPaid;
use Cronos\Checkout\Domain\Exceptions\CheckoutError;
use Cronos\Inventory\Application\Actor;
use Cronos\Inventory\Application\StockLedger;
use Cronos\Inventory\Domain\Exceptions\InsufficientStock;
use Cronos\Inventory\Domain\Ports\WarehouseRepository;
use Cronos\Inventory\Domain\ValueObjects\MovementType;
use Cronos\Payments\Application\OutboxRelay;
use Cronos\Payments\Domain\ValueObjects\PaymentNotice;
use Cronos\Payments\Domain\ValueObjects\PaymentRequest;
use Cronos\Pricing\Domain\ValueObjects\Money;
use Cronos\Promotions\Application\CouponManager;
use Cronos\Shared\Domain\DomainError;
use Database\Seeders\WarehousesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Payments\PaymentsTestCase;

final class CheckoutTest extends PaymentsTestCase
{
    private const ADDRESS = ['recipient_name' => 'Ana Pérez', 'street' => 'Calle 1', 'city' => 'CDMX', 'state' => 'CMX', 'country_code' => 'MX', 'zip' => '01000'];

    private int $mx;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // the outbox relay is driven explicitly
        $this->seed(WarehousesSeeder::class);
        $this->mx = collect(app(WarehouseRepository::class)->all())->firstWhere('code', 'MX-01')['id'];
    }

    private function stocked(string $id, int $amount): string
    {
        app(StockLedger::class)->record($id, $this->mx, MovementType::PURCHASE, $amount, Actor::system(), unitCost: '5.00');

        return $id;
    }

    private function carts(): CartService
    {
        return app(CartService::class);
    }

    private function checkouts(): CheckoutService
    {
        return app(CheckoutService::class);
    }

    private function cartWith(string $productId, string $qty, bool $roll = false, ?string $coupon = null): string
    {
        $cart = $this->carts()->resolve(null, null, 'MX')['cart']['id'];
        $this->carts()->setItem($cart, $productId, $qty, $roll);
        $coupon === null || $this->carts()->setCoupon($cart, $coupon);

        return $cart;
    }

    private function start(string $cart, string $key = 'k1'): array
    {
        return $this->checkouts()->start($cart, null, 'Ana@Example.com', self::ADDRESS, null, $key);
    }

    private function capture(array $checkout, ?string $amount = null, string $event = 'evt_c1'): string
    {
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response($this->stripeIntent(), 200)]);
        $paymentId = $this->checkouts()->pay($checkout['id'], 'stripe', 'https://shop.test/r', 'https://shop.test/c', null)['payment']['id'];
        $this->payments()->applyNotice($paymentId, new PaymentNotice(PaymentNotice::KIND_STATUS, $paymentId, 'pi_1', 'captured', $amount ?? $checkout['total'], $checkout['currency'], $event), 'webhook', $event);
        app(OutboxRelay::class)->run();

        return $paymentId;
    }

    public function test_cut_and_roll_quantities_are_validated_like_the_catalogue_sells_them(): void
    {
        $ribbon = $this->published($this->ribbon());
        $cart = $this->carts()->resolve(null, null, 'MX')['cart']['id'];

        $this->carts()->setItem($cart, $ribbon, '2.5', false);
        $this->carts()->setItem($cart, $ribbon, '2', true);
        $this->assertSame([['product_id' => $ribbon, 'quantity' => '2', 'by_roll' => true]], DB::table('cart_items')->get()->map(fn ($r) => ['product_id' => $r->product_id, 'quantity' => rtrim(rtrim((string) $r->quantity, '0'), '.'), 'by_roll' => (bool) $r->by_roll])->all());

        foreach ([['2.3', false], ['0.5', false], ['1.5', true], ['100', false]] as [$qty, $roll]) {
            try {
                $this->carts()->setItem($cart, $ribbon, $qty, $roll);
                $this->fail("{$qty} should be rejected");
            } catch (CheckoutError|DomainError) {
                $this->addToAssertionCount(1);
            }
        }

        $this->carts()->setItem($cart, $ribbon, '0', false);
        $this->assertSame(0, DB::table('cart_items')->count(), 'zero removes the line');
    }

    public function test_a_guest_cart_follows_the_shopper_into_the_account_and_only_the_owner_can_touch_it(): void
    {
        $a = $this->published($this->piece());
        $b = $this->published($this->piece('Otro', 'OTRO-1'));
        $guest = $this->carts()->resolve(null, null, 'MX');
        $this->assertNotNull($guest['token']);
        $this->carts()->setItem($guest['cart']['id'], $a, '2', false);
        $this->assertSame(hash('sha256', $guest['token']), DB::table('carts')->value('token_hash'), 'only the hash is stored');

        $mine = $this->carts()->resolve('user-1', null, 'MX')['cart'];
        $this->carts()->setItem($mine['id'], $a, '5', false);
        $this->carts()->setItem($mine['id'], $b, '1', false);

        $merged = $this->carts()->resolve('user-1', $guest['token'], 'MX')['cart'];
        $this->assertSame($mine['id'], $merged['id']);
        $items = collect(DB::table('cart_items')->where('cart_id', $mine['id'])->get())->mapWithKeys(fn ($r) => [$r->product_id => rtrim(rtrim((string) $r->quantity, '0'), '.')]);
        $this->assertSame(['5', '1'], [$items[$a], $items[$b]], 'the larger quantity wins; nothing doubles');
        $this->assertSame('abandoned', DB::table('carts')->where('id', $guest['cart']['id'])->value('status'));

        $this->expectException(CheckoutError::class);
        $this->carts()->authorize($mine['id'], 'someone-else', 'wrong-token');
    }

    public function test_the_checkout_freezes_prices_tax_and_shipping_and_holds_the_stock(): void
    {
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 10);
        $cart = $this->cartWith($bow, '2');

        $c = $this->start($cart);

        $this->assertSame(['200.00', '150.00', '350.00', 'ana@example.com', 'open'], [$c['subtotal'], $c['shipping'], $c['total'], $c['email'], $c['status']]);
        $this->assertSame('27.59', $c['tax'], 'IVA is inside the 200.00: 200 x 16/116');
        $this->assertSame('100.00', $c['lines'][0]['unit_price']);
        $this->assertSame('Calle 1', $c['shipping_address']['street']);
        $this->assertNotSame('Calle 1', DB::table('checkouts')->value('shipping_address'), 'addresses are encrypted at rest');
        $this->assertSame('2.000', (string) DB::table('stock_levels')->where('product_id', $bow)->value('reserved'));

        DB::table('products')->where('id', $bow)->update(['price' => '999.00']);
        $this->assertSame('350.00', $this->checkouts()->require($c['id'])['total'], 'a later price change does not touch an open checkout');
    }

    public function test_free_shipping_above_the_threshold_and_a_coupon_are_part_of_the_snapshot(): void
    {
        app(CouponManager::class)->create(['code' => 'DIEZ', 'name' => 'Diez', 'type' => 'percentage', 'value' => '10']);
        $bow = $this->stocked($this->published($this->piece(price: '1000.00')), 10);
        $c = $this->start($this->cartWith($bow, '2', coupon: 'diez'));

        $this->assertSame(['2000.00', '200.00', '0.00', '1800.00', 'DIEZ'], [$c['subtotal'], $c['items_discount'], $c['shipping'], $c['total'], $c['coupon_code']]);
    }

    public function test_ribbons_by_the_roll_reserve_the_metres_they_contain(): void
    {
        $ribbon = $this->stocked($this->published($this->ribbon()), 100);
        $c = $this->start($this->cartWith($ribbon, '2', roll: true));

        $this->assertSame('800.00', $c['subtotal'], '2 rolls x 400.00');
        $this->assertSame('50.000', (string) DB::table('stock_levels')->where('product_id', $ribbon)->value('reserved'), '2 rolls = 50 m');
    }

    public function test_starting_twice_with_the_same_key_is_one_checkout_and_one_reservation(): void
    {
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 10);
        $cart = $this->cartWith($bow, '3');

        $first = $this->start($cart, 'same');
        $second = $this->start($cart, 'same');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, DB::table('checkouts')->count());
        $this->assertSame('3.000', (string) DB::table('stock_levels')->where('product_id', $bow)->value('reserved'));

        $other = $this->carts()->resolve(null, null, 'MX')['cart']['id'];
        $this->expectException(CheckoutError::class);
        $this->start($other, 'same');
    }

    public function test_without_stock_nothing_is_created_and_a_new_checkout_replaces_the_old_one(): void
    {
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 3);
        $cart = $this->cartWith($bow, '5');
        try {
            $this->start($cart);
            $this->fail('expected InsufficientStock');
        } catch (InsufficientStock) {
            $this->assertSame(0, DB::table('checkouts')->count());
        }

        $this->carts()->setItem($cart, $bow, '2', false);
        $old = $this->start($cart, 'a');
        $this->carts()->setItem($cart, $bow, '3', false);
        $new = $this->start($cart, 'b');

        $this->assertSame('cancelled', DB::table('checkouts')->where('id', $old['id'])->value('status'));
        $this->assertSame('open', DB::table('checkouts')->where('id', $new['id'])->value('status'));
        $this->assertSame('3.000', (string) DB::table('stock_levels')->where('product_id', $bow)->value('reserved'), 'the old hold was given back, the new one taken');
    }

    public function test_the_shipping_country_must_match_the_market_and_addresses_are_checked(): void
    {
        $cart = $this->cartWith($this->stocked($this->published($this->piece()), 5), '1');
        foreach ([['country_code' => 'US'], ['street' => ' '], ['country_code' => 'MEX']] as $i => $bad) {
            try {
                $this->checkouts()->start($cart, null, 'a@b.co', $bad + self::ADDRESS, null, "bad-{$i}");
                $this->fail('expected CheckoutError');
            } catch (CheckoutError) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, DB::table('checkouts')->count());
    }

    public function test_a_captured_payment_sells_the_stock_burns_the_coupon_and_closes_the_cart_exactly_once(): void
    {
        Event::fake([CheckoutPaid::class]);
        app(CouponManager::class)->create(['code' => 'DIEZ', 'name' => 'Diez', 'type' => 'percentage', 'value' => '10', 'max_uses' => 1]);
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 10);
        $cart = $this->cartWith($bow, '2', coupon: 'DIEZ');
        $c = $this->start($cart);
        $this->assertSame('330.00', $c['total']);

        $paymentId = $this->capture($c);
        DB::table('outbox_messages')->update(['dispatched_at' => null, 'attempts' => 0]); // redelivery of the same announcement
        app(OutboxRelay::class)->run();

        $row = DB::table('checkouts')->where('id', $c['id'])->first();
        $this->assertSame(['paid', $paymentId], [$row->status, $row->payment_id]);
        $this->assertNotNull($row->paid_at);
        $this->assertSame('converted', DB::table('carts')->where('id', $cart)->value('status'));
        $level = DB::table('stock_levels')->where('product_id', $bow)->first();
        $this->assertSame(['8.000', '0.000'], [(string) $level->on_hand, (string) $level->reserved]);
        $this->assertSame(1, DB::table('inventory_movements')->where(['reference_type' => 'order', 'reference_id' => $c['id']])->count(), 'sold once in the Kardex');
        $this->assertSame(1, DB::table('coupon_usages')->count());
        Event::assertDispatchedTimes(CheckoutPaid::class, 1);
    }

    public function test_a_second_payment_for_a_paid_checkout_is_flagged_not_lost(): void
    {
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 10);
        $c = $this->start($this->cartWith($bow, '1'));
        $first = $this->capture($c);

        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response($this->stripeIntent(), 200)]);
        $second = $this->payments()->start(new PaymentRequest('stripe', 'checkout', $c['id'], new Money($c['total'], 'MXN'), 'MX', 'rogue-key'))['payment']['id'];
        $this->payments()->applyNotice($second, new PaymentNotice(PaymentNotice::KIND_STATUS, $second, 'pi_1', 'captured', $c['total'], 'MXN', 'evt_c2'), 'webhook', 'evt_c2');
        app(OutboxRelay::class)->run();

        $this->assertNotSame($first, $second);
        $row = DB::table('checkouts')->where('id', $c['id'])->first();
        $this->assertSame(['paid', $first], [$row->status, $row->payment_id]);
        $this->assertStringContainsString('duplicate_payment', $row->notes);
    }

    public function test_money_that_does_not_match_goes_to_review_and_keeps_the_payment(): void
    {
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 10);
        $c = $this->start($this->cartWith($bow, '1'));

        $this->capture($c, amount: '100.00');

        $row = DB::table('checkouts')->where('id', $c['id'])->first();
        $this->assertSame('open', $row->status, 'the payment layer refused to call it captured, so the checkout is not paid');
        $this->assertStringContainsString('payment_needs_review', $row->notes);
        $this->assertSame(10, (int) DB::table('stock_levels')->where('product_id', $bow)->value('on_hand'), 'stock is not sold on a payment nobody verified');
    }

    public function test_an_unpaid_checkout_expires_and_returns_the_stock_but_waits_for_money_in_flight(): void
    {
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 10);
        $quiet = $this->start($this->cartWith($bow, '2'), 'quiet');
        DB::table('checkouts')->where('id', $quiet['id'])->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(1, $this->checkouts()->expireDue());
        $this->assertSame('expired', DB::table('checkouts')->where('id', $quiet['id'])->value('status'));
        $this->assertSame('0.000', (string) DB::table('stock_levels')->where('product_id', $bow)->value('reserved'));

        $busy = $this->start($this->cartWith($bow, '2'), 'busy');
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response($this->stripeIntent(), 200)]);
        $this->checkouts()->pay($busy['id'], 'stripe', null, null, null);
        DB::table('checkouts')->where('id', $busy['id'])->update(['expires_at' => now()->subMinutes(5)]);

        $this->assertSame(0, $this->checkouts()->expireDue(), 'a charge may still land: keep the stock');
        $this->assertSame('open', DB::table('checkouts')->where('id', $busy['id'])->value('status'));

        DB::table('checkouts')->where('id', $busy['id'])->update(['expires_at' => now()->subMinutes(90)]);
        $this->assertSame(1, $this->checkouts()->expireDue());
        $this->assertSame('cancelled', DB::table('payments')->where('payable_id', $busy['id'])->value('status'));
    }

    public function test_money_arriving_after_expiry_is_never_ignored(): void
    {
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 10);
        $c = $this->start($this->cartWith($bow, '1'));
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response($this->stripeIntent(), 200)]);
        $paymentId = $this->checkouts()->pay($c['id'], 'stripe', null, null, null)['payment']['id'];
        DB::table('checkouts')->where('id', $c['id'])->update(['expires_at' => now()->subHours(3)]);
        $this->checkouts()->expireDue();

        $this->payments()->applyNotice($paymentId, new PaymentNotice(PaymentNotice::KIND_STATUS, $paymentId, 'pi_1', 'captured', $c['total'], 'MXN', 'evt_late'), 'webhook', 'evt_late');
        app(OutboxRelay::class)->run();

        $this->assertSame('needs_review', DB::table('checkouts')->where('id', $c['id'])->value('status'));
    }

    public function test_paying_offers_only_methods_that_fit_and_switching_cancels_the_open_one(): void
    {
        $bow = $this->stocked($this->published($this->piece(price: '100.00')), 10);
        $c = $this->start($this->cartWith($bow, '1'));

        $this->assertEqualsCanonicalizing(['stripe', 'mercadopago'], array_column($this->checkouts()->paymentOptions($c['id']), 'provider'));

        Http::fake([
            'api.stripe.com/v1/payment_intents' => Http::response($this->stripeIntent(), 200),
            'api.mercadopago.com/*' => Http::response(['id' => 'pref-1', 'init_point' => 'https://mp.test/pay', 'sandbox_init_point' => 'https://mp.test/sandbox'], 201),
        ]);
        $this->checkouts()->pay($c['id'], 'stripe', 'https://shop.test/r', 'https://shop.test/c', null);
        $this->checkouts()->pay($c['id'], 'mercadopago', 'https://shop.test/r', 'https://shop.test/c', null);

        $statuses = DB::table('payments')->where('payable_id', $c['id'])->pluck('status', 'provider')->all();
        $this->assertSame('cancelled', $statuses['stripe']);
        $this->assertContains($statuses['mercadopago'], ['pending', 'requires_action']);
    }
}
