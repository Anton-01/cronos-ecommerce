<?php

namespace Tests\Feature\Orders;

use Cronos\Checkout\Application\CartService;
use Cronos\Checkout\Application\CheckoutService;
use Cronos\Inventory\Application\Actor;
use Cronos\Inventory\Application\StockLedger;
use Cronos\Inventory\Domain\Ports\WarehouseRepository;
use Cronos\Inventory\Domain\ValueObjects\MovementType;
use Cronos\Payments\Application\OutboxRelay;
use Cronos\Payments\Domain\ValueObjects\PaymentNotice;
use Database\Seeders\WarehousesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\TalksGraphQL;
use Tests\Feature\Payments\PaymentsTestCase;

/** Shop with MX warehouse stock and a helper that walks a purchase from cart to captured payment to order. */
abstract class OrdersTestCase extends PaymentsTestCase
{
    use TalksGraphQL;

    protected const ADDRESS = ['recipient_name' => 'Ana Pérez', 'street' => 'Calle 1', 'city' => 'CDMX', 'state' => 'CMX', 'country_code' => 'MX', 'zip' => '01000'];

    protected int $mx;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // the outbox relay is driven explicitly
        $this->seed(WarehousesSeeder::class);
        $this->mx = collect(app(WarehouseRepository::class)->all())->firstWhere('code', 'MX-01')['id'];
    }

    protected function stocked(string $id, int $amount): string
    {
        app(StockLedger::class)->record($id, $this->mx, MovementType::PURCHASE, $amount, Actor::system(), unitCost: '5.00');

        return $id;
    }

    /** Buys $qty of the product and returns the order row, with money captured through Stripe (faked). @return array<string, mixed> */
    protected function placeOrder(string $productId, string $qty = '2', ?string $userId = null, string $key = 'order-key'): array
    {
        $cart = app(CartService::class)->resolve($userId, null, 'MX')['cart']['id'];
        app(CartService::class)->setItem($cart, $productId, $qty, false);
        $checkout = app(CheckoutService::class)->start($cart, $userId, 'ana@example.com', self::ADDRESS, null, $key);

        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response($this->stripeIntent(), 200)]);
        $paymentId = app(CheckoutService::class)->pay($checkout['id'], 'stripe', null, null, $userId)['payment']['id'];
        $this->payments()->applyNotice($paymentId, new PaymentNotice(PaymentNotice::KIND_STATUS, $paymentId, 'pi_1', 'captured', $checkout['total'], $checkout['currency'], 'evt-'.$key), 'webhook', 'evt-'.$key);
        app(OutboxRelay::class)->run();

        $order = DB::table('orders')->where('checkout_id', $checkout['id'])->first();
        $this->assertNotNull($order, 'the paid checkout became an order');

        return (array) $order;
    }
}
