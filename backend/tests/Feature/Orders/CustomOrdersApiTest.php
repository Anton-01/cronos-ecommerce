<?php

namespace Tests\Feature\Orders;

use Cronos\Payments\Application\OutboxRelay;
use Cronos\Payments\Domain\ValueObjects\PaymentNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class CustomOrdersApiTest extends OrdersTestCase
{
    private const ADDR = '{recipientName: "Ana", street: "Calle 1", city: "CDMX", state: "CMX", countryCode: "MX", zip: "01000"}';

    public function test_a_customer_and_staff_take_a_made_to_measure_order_from_request_to_paid_order(): void
    {
        $this->makeUser('ana@example.com');
        $ana = $this->customerToken('ana@example.com');
        $staff = $this->adminToken();

        $req = $this->gql('mutation($i: SubmitCustomOrderInput!) { submitCustomOrder(input: $i) { id number status messages { author body } } }',
            ['i' => ['market' => 'MX', 'title' => 'Listón bordado', 'description' => 'Necesito 40 metros bordados con nombres.', 'specifications' => ['meters' => 40], 'budget' => '3000']], $ana)
            ->assertJsonMissingPath('errors')->json('data.submitCustomOrder');
        $this->assertSame('submitted', $req['status']);

        $this->adminGql('{ adminCustomOrders(status: "submitted") { total rows { number email } } }', [], $staff)->assertJsonPath('data.adminCustomOrders.total', 1);
        $this->adminGql('mutation($id: ID!) { addCustomOrderStaffMessage(id: $id, body: "Nota interna", internal: true) { messages { internal } } }', ['id' => $req['id']], $staff);
        $q = $this->adminGql('mutation($i: CreateQuoteInput!) { createQuote(input: $i) { id version status total tax } }',
            ['i' => ['requestId' => $req['id'], 'lines' => [['description' => 'Listón bordado', 'unit' => 'meter', 'quantity' => '10', 'unitPrice' => '50']], 'shipping' => '100', 'validUntil' => '2099-01-01']], $staff)
            ->assertJsonMissingPath('errors')->json('data.createQuote');
        $this->assertSame(['draft', '600.00', '68.97'], [$q['status'], $q['total'], $q['tax']]);

        $mine = $this->gql('query($n: String!) { myCustomOrder(number: $n) { status quotes { id } messages { body } } }', ['n' => $req['number']], $ana)->json('data.myCustomOrder');
        $this->assertSame([], $mine['quotes'], 'a draft is invisible to the customer');
        $this->assertNotContains('Nota interna', array_column($mine['messages'], 'body'));

        $this->adminGql('mutation($id: ID!) { sendQuote(id: $id) { status } }', ['id' => $q['id']], $staff)->assertJsonPath('data.sendQuote.status', 'sent');
        $this->gql('query($n: String!) { myCustomOrder(number: $n) { status quotes { id status total } } }', ['n' => $req['number']], $ana)
            ->assertJsonPath('data.myCustomOrder.status', 'quoted')->assertJsonPath('data.myCustomOrder.quotes.0.total', '600.00');

        $order = $this->gql('mutation($q: ID!) { acceptQuote(quoteId: $q, shippingAddress: '.self::ADDR.') { number status paymentStatus total } }', ['q' => $q['id']], $ana)
            ->assertJsonMissingPath('errors')->json('data.acceptQuote');
        $this->assertSame(['pending', 'unpaid', '600.00'], [$order['status'], $order['paymentStatus'], $order['total']]);

        $this->gql('query($n: String!) { orderPaymentOptions(number: $n) { provider mode } }', ['n' => $order['number']], $ana)->assertJsonFragment(['provider' => 'stripe']);
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response($this->stripeIntent(), 200)]);
        $this->gql('mutation($n: String!, $r: String!) { payOrder(number: $n, provider: "stripe", returnUrl: $r, cancelUrl: $r) { paymentId mode } }', ['n' => $order['number'], 'r' => 'https://evil.test/x'], $ana)
            ->assertJsonPath('errors.0.extensions.code', 'INVALID_RETURN_URL');
        $paymentId = $this->gql('mutation($n: String!) { payOrder(number: $n, provider: "stripe", returnUrl: "http://localhost:3000/ok", cancelUrl: "http://localhost:3000/no") { paymentId mode } }', ['n' => $order['number']], $ana)
            ->assertJsonMissingPath('errors')->json('data.payOrder.paymentId');

        $this->payments()->applyNotice($paymentId, new PaymentNotice(PaymentNotice::KIND_STATUS, $paymentId, 'pi_1', 'captured', '600.00', 'MXN', 'evt-x'), 'webhook', 'evt-x');
        app(OutboxRelay::class)->run();
        $this->gql('query($n: String!) { myOrder(number: $n) { status paymentStatus } }', ['n' => $order['number']], $ana)->assertJsonPath('data.myOrder.status', 'processing')->assertJsonPath('data.myOrder.paymentStatus', 'paid');
    }

    public function test_other_customers_and_support_staff_are_kept_out(): void
    {
        $this->makeUser('ana@example.com');
        $this->makeUser('eve@example.com');
        $ana = $this->customerToken('ana@example.com');
        $eve = $this->customerToken('eve@example.com');
        $r = $this->gql('mutation($i: SubmitCustomOrderInput!) { submitCustomOrder(input: $i) { number } }', ['i' => ['market' => 'MX', 'title' => 'Moños', 'description' => 'Cien moños de satén rojo.']], $ana)->json('data.submitCustomOrder');

        $this->gql('query($n: String!) { myCustomOrder(number: $n) { number } }', ['n' => $r['number']], $eve)->assertJsonPath('data.myCustomOrder', null);
        $this->gql('mutation($n: String!) { addCustomOrderMessage(number: $n, body: "hola") { number } }', ['n' => $r['number']], $eve)->assertJsonPath('errors.0.extensions.code', 'REQUEST_NOT_FOUND');

        $support = $this->adminToken('help@example.com', 'support');
        $this->adminGql('{ adminCustomOrders { total } }', [], $support)->assertJsonPath('data.adminCustomOrders.total', 1);
        $this->adminGql('mutation($id: ID!) { setCustomOrderStatus(id: $id, to: "in_review") { status } }', ['id' => DB::table('custom_order_requests')->value('id')], $support)
            ->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'unauthorized'));
    }

    public function test_the_wishlist_keeps_published_products_once_and_per_shopper(): void
    {
        $this->makeUser('ana@example.com');
        $this->makeUser('eve@example.com');
        $ana = $this->customerToken('ana@example.com');
        $bow = $this->published($this->piece('Moño rojo', 'MONO-R'));
        $draft = $this->piece('Moño borrador', 'MONO-D');

        $this->gql('mutation($p: ID!) { addToWishlist(productId: $p) }', ['p' => $bow], $ana)->assertJsonPath('data.addToWishlist', true);
        $this->gql('mutation($p: ID!) { addToWishlist(productId: $p) }', ['p' => $bow], $ana)->assertJsonPath('data.addToWishlist', true);
        $this->gql('mutation($p: ID!) { addToWishlist(productId: $p) }', ['p' => $draft], $ana)->assertJsonPath('errors.0.extensions.code', 'PRODUCT_UNAVAILABLE');
        $this->assertSame(1, DB::table('wishlist_items')->count(), 'adding twice is adding once');

        $this->gql('{ myWishlist { total items { id name } } }', [], $ana)->assertJsonPath('data.myWishlist.total', 1)->assertJsonPath('data.myWishlist.items.0.name', 'Moño rojo');
        $this->gql('query($p: [ID!]!) { wishlistContains(productIds: $p) }', ['p' => [$bow, $draft]], $ana)->assertJsonPath('data.wishlistContains', [$bow]);
        $this->gql('{ myWishlist { total } }', [], $this->customerToken('eve@example.com'))->assertJsonPath('data.myWishlist.total', 0);

        $this->gql('mutation($p: ID!) { removeFromWishlist(productId: $p) }', ['p' => $bow], $ana);
        $this->gql('{ myWishlist { total } }', [], $ana)->assertJsonPath('data.myWishlist.total', 0);
        $this->gql('{ myWishlist { total } }')->assertJsonPath('errors.0.message', fn ($m) => str_contains($m, 'Unauthenticated'));
    }
}
