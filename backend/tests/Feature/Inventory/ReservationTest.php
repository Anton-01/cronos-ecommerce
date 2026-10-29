<?php

namespace Tests\Feature\Inventory;

use Cronos\Inventory\Application\InventoryReconciler;
use Cronos\Inventory\Application\ReservationService;
use Cronos\Inventory\Application\StockAdjuster;
use Cronos\Inventory\Application\StockLedger;
use Cronos\Inventory\Domain\Exceptions\InsufficientStock;
use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Cronos\Inventory\Domain\Exceptions\NotFound;
use Cronos\Inventory\Domain\ValueObjects\MovementType;
use Illuminate\Support\Facades\DB;

final class ReservationTest extends InventoryTestCase
{
    private function reservations(): ReservationService
    {
        return app(ReservationService::class);
    }

    public function test_a_reservation_holds_stock_without_touching_the_kardex(): void
    {
        $roll = $this->ribbon();
        $this->stock($roll, 30);

        $this->reservations()->reserve('cart', 'cart-1', [['product_id' => $roll, 'quantity' => '12.5']]);

        $this->assertSame(['30.000', '12.500', '17.500'], array_values($this->level($roll)));
        $this->assertSame('17.500', $this->productStock($roll), 'the shop window shows what is still free');
        $this->assertSame(1, DB::table('inventory_movements')->count(), 'only the purchase is in the Kardex');
    }

    public function test_two_shoppers_cannot_both_hold_the_last_metres(): void
    {
        $roll = $this->ribbon();
        $this->stock($roll, 10);
        $this->reservations()->reserve('cart', 'a', [['product_id' => $roll, 'quantity' => 8]]);

        try {
            $this->reservations()->reserve('cart', 'b', [['product_id' => $roll, 'quantity' => 3]]);
            $this->fail('expected insufficient stock');
        } catch (InsufficientStock $e) {
            $this->assertSame(['3.000', '2.000'], [$e->requested, $e->available]);
        }
        $this->reservations()->reserve('cart', 'b', [['product_id' => $roll, 'quantity' => 2]]);
        $this->assertSame('10.000', $this->level($roll)['reserved']);
    }

    public function test_reserving_again_for_the_same_cart_replaces_the_amount_instead_of_piling_up(): void
    {
        $bow = $this->piece();
        $this->stock($bow, 10);
        $this->reservations()->reserve('cart', 'a', [['product_id' => $bow, 'quantity' => 4]]);
        $this->reservations()->reserve('cart', 'a', [['product_id' => $bow, 'quantity' => 6]]); // the shopper raised the quantity
        $this->assertSame('6.000', $this->level($bow)['reserved']);

        $this->reservations()->reserve('cart', 'a', [['product_id' => $bow, 'quantity' => 10]]); // exactly everything, counting its own hold
        $this->assertSame('0.000', $this->level($bow)['available']);
        $this->assertSame(1, DB::table('inventory_reservations')->where('status', 'active')->count());

        $this->expectException(InsufficientStock::class);
        $this->reservations()->reserve('cart', 'a', [['product_id' => $bow, 'quantity' => 11]]);
    }

    public function test_a_cart_is_reserved_completely_or_not_at_all(): void
    {
        $a = $this->piece(name: 'A', sku: 'A');
        $b = $this->piece(name: 'B', sku: 'B');
        $this->stock($a, 10);
        $this->stock($b, 1);

        try {
            $this->reservations()->reserve('cart', 'c', [['product_id' => $a, 'quantity' => 5], ['product_id' => $b, 'quantity' => 2]]);
            $this->fail('expected insufficient stock');
        } catch (InsufficientStock $e) {
            $this->assertSame('B', $e->sku);
        }
        $this->assertSame(['0.000', '0.000'], [$this->level($a)['reserved'], $this->level($b)['reserved']]);
        $this->assertSame(0, DB::table('inventory_reservations')->count());
    }

    public function test_releasing_a_cart_gives_everything_back(): void
    {
        $bow = $this->piece();
        $this->stock($bow, 10);
        $this->reservations()->reserve('cart', 'a', [['product_id' => $bow, 'quantity' => 7]]);

        $this->assertSame(1, $this->reservations()->release('cart', 'a'));
        $this->assertSame(['10.000', '0.000'], [$this->level($bow)['on_hand'], $this->level($bow)['reserved']]);
        $this->assertSame('10.000', $this->productStock($bow));
        $this->assertSame(0, $this->reservations()->release('cart', 'a'), 'releasing twice is harmless');
    }

    public function test_reservations_expire_on_their_own(): void
    {
        $bow = $this->piece();
        $this->stock($bow, 10);
        $this->reservations()->reserve('cart', 'old', [['product_id' => $bow, 'quantity' => 4]], ttlMinutes: 15);
        $this->reservations()->reserve('cart', 'fresh', [['product_id' => $bow, 'quantity' => 1]], ttlMinutes: 120);

        $this->assertSame(0, $this->reservations()->expireDue(new \DateTimeImmutable('+10 minutes')));
        $this->assertSame(1, $this->reservations()->expireDue(new \DateTimeImmutable('+30 minutes')));

        $this->assertSame('1.000', $this->level($bow)['reserved']);
        $this->assertSame(['expired', 'active'], DB::table('inventory_reservations')->orderBy('id')->pluck('status')->all());
        $this->artisan('inventory:expire-reservations')->assertSuccessful();
    }

    public function test_paying_turns_the_reservation_into_a_sale_in_the_kardex(): void
    {
        $roll = $this->ribbon();
        $this->stock($roll, 30);
        $this->reservations()->reserve('order', 'order-9', [['product_id' => $roll, 'quantity' => '12.5']]);

        $movements = $this->reservations()->consume('order', 'order-9', 'order-9', $this->actor());

        $this->assertCount(1, $movements);
        $row = DB::table('inventory_movements')->where('ulid', $movements[0])->first();
        $this->assertSame(['reservation_consume', '-12.500', '17.500', 'order', 'order-9'], [$row->type, (string) $row->quantity, (string) $row->balance_after, $row->reference_type, $row->reference_id]);
        $this->assertSame(['17.500', '0.000'], [$this->level($roll)['on_hand'], $this->level($roll)['reserved']]);
        $this->assertSame('17.500', $this->productStock($roll));
        $this->assertSame('consumed', DB::table('inventory_reservations')->value('status'));

        $this->expectException(NotFound::class);
        $this->reservations()->consume('order', 'order-9', 'order-9', $this->actor()); // nothing left to consume
    }

    public function test_reserved_stock_cannot_be_sold_to_someone_else_or_adjusted_away(): void
    {
        $bow = $this->piece();
        $this->stock($bow, 10);
        $this->reservations()->reserve('cart', 'a', [['product_id' => $bow, 'quantity' => 8]]);
        $ledger = app(StockLedger::class);

        $this->expectException(InsufficientStock::class);
        $ledger->record($bow, $this->mx, MovementType::SALE, 3, $this->actor()); // only 2 are free
    }

    public function test_stock_is_taken_from_the_first_warehouse_that_has_it_preferring_the_markets_own(): void
    {
        $bow = $this->piece();
        $this->stock($bow, 2, $this->mx);
        $this->stock($bow, 50, $this->us);

        $this->reservations()->reserve('cart', 'us-shopper', [['product_id' => $bow, 'quantity' => 5]], marketCode: 'US');
        $this->reservations()->reserve('cart', 'mx-shopper', [['product_id' => $bow, 'quantity' => 5]], marketCode: 'MX'); // MX only has 2: falls over to US

        $byWarehouse = DB::table('inventory_reservations')->orderBy('id')->pluck('warehouse_id', 'holder_id')->all();
        $this->assertSame([$this->us, $this->us], [$byWarehouse['us-shopper'], $byWarehouse['mx-shopper']]);

        $this->reservations()->reserve('cart', 'mx-small', [['product_id' => $bow, 'quantity' => 2]], marketCode: 'MX');
        $this->assertSame($this->mx, (int) DB::table('inventory_reservations')->where('holder_id', 'mx-small')->value('warehouse_id'), 'the market\'s own warehouse is tried first');
    }

    public function test_holders_and_products_are_validated(): void
    {
        $bow = $this->piece();
        $this->stock($bow, 1);

        foreach ([
            fn () => $this->reservations()->reserve('basket', 'a', [['product_id' => $bow, 'quantity' => 1]]),
            fn () => $this->reservations()->reserve('cart', 'a', [['product_id' => $bow, 'quantity' => 1]], ttlMinutes: 0),
            fn () => $this->reservations()->reserve('cart', 'a', [['product_id' => $this->variableRibbon($this->colorAndWidth()), 'quantity' => 1]]),
        ] as $bad) {
            try {
                $bad();
                $this->fail('expected the reservation to be refused');
            } catch (InvalidMovement) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_after_a_busy_sequence_the_books_still_balance(): void
    {
        $roll = $this->ribbon();
        $bow = $this->piece();
        $this->stock($roll, 100);
        $this->stock($bow, 20);
        $this->reservations()->reserve('cart', 'a', [['product_id' => $roll, 'quantity' => '10.5'], ['product_id' => $bow, 'quantity' => 3]]);
        $this->reservations()->reserve('cart', 'b', [['product_id' => $roll, 'quantity' => 4]]);
        $this->reservations()->consume('cart', 'a', 'order-1', $this->actor());
        $this->reservations()->release('cart', 'b');
        app(StockAdjuster::class)->transfer($bow, $this->mx, $this->us, 5, $this->actor());
        app(StockAdjuster::class)->setCount($roll, $this->mx, 80, $this->actor());

        $this->assertSame(['ok' => true, 'chains' => []], ['ok' => app(InventoryReconciler::class)->check()['ok'], 'chains' => app(InventoryReconciler::class)->check()['broken_chains']]);
        $this->artisan('inventory:reconcile')->assertSuccessful();
    }
}
