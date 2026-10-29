<?php

namespace Tests\Feature\Checkout;

use Cronos\Inventory\Application\Actor;
use Cronos\Inventory\Application\StockLedger;
use Cronos\Inventory\Domain\Ports\WarehouseRepository;
use Cronos\Inventory\Domain\ValueObjects\MovementType;
use Database\Seeders\WarehousesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Payments\PaymentsTestCase;

final class CheckoutApiTest extends PaymentsTestCase
{
    private const ADDRESS = '{recipientName: "Ana Pérez", street: "Calle 1", city: "CDMX", state: "CMX", countryCode: "MX", zip: "01000"}';

    private string $bow;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(WarehousesSeeder::class);
        $mx = collect(app(WarehouseRepository::class)->all())->firstWhere('code', 'MX-01')['id'];
        $this->bow = $this->published($this->piece(price: '100.00'));
        app(StockLedger::class)->record($this->bow, $mx, MovementType::PURCHASE, 10, Actor::system(), unitCost: '5.00');
    }

    private function gql(string $query): TestResponse
    {
        return $this->postJson('/graphql', ['query' => $query]);
    }

    public function test_a_guest_fills_a_cart_checks_out_and_starts_a_payment_end_to_end(): void
    {
        $open = $this->gql('mutation { openCart(market: "MX") { token cart { id } } }')->assertJsonPath('errors', null)->json('data.openCart');
        $token = $open['token'];
        $cart = $open['cart']['id'];
        $this->assertNotEmpty($token);

        $this->gql("mutation { setCartItem(cartId: \"{$cart}\", token: \"{$token}\", productId: \"{$this->bow}\", quantity: \"2\") { lines { name quantity total } totals { total shipping tax taxIncluded } } }")
            ->assertJsonPath('data.setCartItem.totals.total', '350.00')
            ->assertJsonPath('data.setCartItem.totals.tax', '27.59')
            ->assertJsonPath('data.setCartItem.lines.0.quantity', '2');

        $checkout = $this->gql("mutation { startCheckout(input: {cartId: \"{$cart}\", token: \"{$token}\", email: \"ana@example.com\", shippingAddress: ".self::ADDRESS.', idempotencyKey: "attempt-0001"}) { id status totals { total } } }')
            ->assertJsonPath('errors', null)->json('data.startCheckout');
        $this->assertSame(['open', '350.00'], [$checkout['status'], $checkout['totals']['total']]);

        $this->gql("{ checkoutPaymentOptions(id: \"{$checkout['id']}\", token: \"{$token}\") { provider mode } }")
            ->assertJsonFragment(['provider' => 'stripe', 'mode' => 'SANDBOX']);

        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response($this->stripeIntent(), 200)]);
        $this->gql("mutation { payCheckout(id: \"{$checkout['id']}\", token: \"{$token}\", provider: \"stripe\", returnUrl: \"http://localhost:3000/ok\", cancelUrl: \"http://localhost:3000/no\") { paymentId status mode clientAction publicConfig } }")
            ->assertJsonPath('errors', null)
            ->assertJsonPath('data.payCheckout.mode', 'SANDBOX')
            ->assertJsonPath('data.payCheckout.publicConfig.publishable_key', self::STRIPE['publishable_key']);
    }

    public function test_a_cart_and_checkout_cannot_be_read_with_a_wrong_or_missing_token(): void
    {
        $open = $this->gql('mutation { openCart(market: "MX") { token cart { id } } }')->json('data.openCart');
        $cart = $open['cart']['id'];

        $this->gql("{ cart(id: \"{$cart}\", token: \"nope\") { id } }")->assertJsonPath('errors.0.extensions.code', 'CART_NOT_FOUND');
        $this->gql("{ cart(id: \"{$cart}\") { id } }")->assertJsonPath('errors.0.extensions.code', 'CART_NOT_FOUND');
        $this->gql("{ cart(id: \"{$cart}\", token: \"{$open['token']}\") { id totals { total } lines { name } } }")->assertJsonPath('data.cart.lines', []);
    }

    public function test_a_bad_coupon_is_reported_on_the_cart_instead_of_breaking_it_and_return_urls_must_be_ours(): void
    {
        $open = $this->gql('mutation { openCart(market: "MX") { token cart { id } } }')->json('data.openCart');
        [$cart, $token] = [$open['cart']['id'], $open['token']];
        $this->gql("mutation { setCartItem(cartId: \"{$cart}\", token: \"{$token}\", productId: \"{$this->bow}\", quantity: \"1\") { id } }");

        $this->gql("mutation { applyCartCoupon(cartId: \"{$cart}\", token: \"{$token}\", code: \"NOEXISTE\") { couponError totals { total } } }")
            ->assertJsonPath('errors', null)
            ->assertJsonPath('data.applyCartCoupon.totals.total', '250.00');

        $start = "mutation { startCheckout(input: {cartId: \"{$cart}\", token: \"{$token}\", email: \"ana@example.com\", shippingAddress: ".self::ADDRESS.', idempotencyKey: "attempt-0002"}) { id } }';
        $this->gql($start)->assertJsonPath('errors.0.extensions.code', 'COUPON_NOT_FOUND'); // checking out never silently drops a code the shopper typed
        $this->gql("mutation { applyCartCoupon(cartId: \"{$cart}\", token: \"{$token}\", code: null) { couponCode } }");
        $id = $this->gql($start)->json('data.startCheckout.id');
        $this->gql("mutation { payCheckout(id: \"{$id}\", token: \"{$token}\", provider: \"stripe\", returnUrl: \"https://evil.test/x\", cancelUrl: \"http://localhost:3000/no\") { paymentId } }")
            ->assertJsonPath('errors.0.extensions.code', 'INVALID_RETURN_URL');
        $this->assertSame(0, DB::table('payments')->count());
    }
}
