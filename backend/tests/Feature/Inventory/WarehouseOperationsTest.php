<?php

namespace Tests\Feature\Inventory;

use Cronos\Inventory\Application\Actor;
use Cronos\Inventory\Application\AdjustmentRequests;
use Cronos\Inventory\Application\CountSessions;
use Cronos\Inventory\Application\InventoryReconciler;
use Cronos\Inventory\Application\LocationLedger;
use Cronos\Inventory\Application\LocationManager;
use Cronos\Inventory\Application\LocationReconciler;
use Cronos\Inventory\Application\PickLists;
use Cronos\Inventory\Application\ReservationService;
use Cronos\Inventory\Application\WarehouseOperations;
use Cronos\Inventory\Domain\Exceptions\Conflict;
use Cronos\Inventory\Domain\Exceptions\InsufficientStock;
use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class WarehouseOperationsTest extends InventoryTestCase
{
    private function ops(): WarehouseOperations
    {
        return app(WarehouseOperations::class);
    }

    private function shelf(string $code): string
    {
        return app(LocationManager::class)->create($this->mx, ['code' => $code, 'name' => $code]);
    }

    private function onShelf(string $product, string $location): string
    {
        return (string) DB::table('location_stock')->where(['product_id' => $product, 'location_id' => $location])->value('quantity');
    }

    private function version(string $product, string $location): int
    {
        return (int) DB::table('location_stock')->where(['product_id' => $product, 'location_id' => $location])->value('version');
    }

    private function user(string $n): Actor
    {
        return new Actor(str_pad($n, 26, '0'));
    }

    public function test_receiving_raises_the_books_and_the_shelf_together_and_a_retry_does_nothing(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        $one = $this->ops()->receive($roll, $this->mx, 30, $this->actor(), $a, '4.50', 'rcv-1');
        $two = $this->ops()->receive($roll, $this->mx, 30, $this->actor(), $a, '4.50', 'rcv-1');

        $this->assertSame($one, $two);
        $this->assertSame('30.000', $this->level($roll)['on_hand']);
        $this->assertSame('30.000', $this->onShelf($roll, $a));
        $this->assertSame(1, DB::table('inventory_movements')->where('product_id', $roll)->count());
    }

    public function test_receiving_without_a_place_goes_to_reception_and_a_putaway_moves_it_without_touching_the_books(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        $this->ops()->receive($roll, $this->mx, 12, $this->actor());
        $reception = DB::table('warehouse_locations')->where(['warehouse_id' => $this->mx, 'code' => 'RECEPCION'])->value('id');
        $this->assertSame('12.000', $this->onShelf($roll, $reception));

        $before = DB::table('inventory_movements')->count();
        app(LocationLedger::class)->move($roll, $reception, $a, 12, $this->actor(), 'put-1');
        $this->assertSame($before, DB::table('inventory_movements')->count(), 'a shelf move never reaches the books');
        $this->assertSame('12.000', $this->onShelf($roll, $a));
    }

    public function test_picking_confirms_only_what_the_order_took_and_only_once_per_unit(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        $b = $this->shelf('B');
        $this->ops()->receive($roll, $this->mx, 10, $this->actor(), $a);
        $this->ops()->receive($roll, $this->mx, 10, $this->actor(), $b);
        app(ReservationService::class)->reserve('order', 'order-1', [['product_id' => $roll, 'quantity' => 6]], 15);
        app(ReservationService::class)->consume('order', 'order-1', 'order-1', $this->actor());

        $picks = app(PickLists::class);
        $this->assertSame([['order_id' => 'order-1', 'product_id' => $roll, 'warehouse_id' => $this->mx, 'pending' => '6.000']], $picks->pending($this->mx));

        $picks->pick('order-1', $roll, $a, 4, $this->actor(), 'pk-1');
        $picks->pick('order-1', $roll, $a, 4, $this->actor(), 'pk-1'); // double tap
        $picks->pick('order-1', $roll, $b, 2, $this->actor(), 'pk-2');

        $this->assertSame([], $picks->pending($this->mx));
        $this->assertSame(['6.000', '8.000'], [$this->onShelf($roll, $a), $this->onShelf($roll, $b)]);
        $this->expectException(InvalidMovement::class);
        $picks->pick('order-1', $roll, $b, 1, $this->actor());
    }

    public function test_a_small_adjustment_applies_at_once_a_big_one_waits_for_someone_else(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        $this->ops()->receive($roll, $this->mx, 20, $this->actor(), $a);
        $req = app(AdjustmentRequests::class);

        $small = $req->request($roll, $a, 18, $this->version($roll, $a), 'Two cut off by mistake', $this->user('1'));
        $this->assertSame('approved', $small['status']);
        $this->assertSame(['18.000', '18.000'], [$this->onShelf($roll, $a), $this->level($roll)['on_hand']]);

        $big = $req->request($roll, $a, 5, $this->version($roll, $a), 'Roll missing', $this->user('1'));
        $this->assertSame('pending', $big['status']);
        $this->assertSame('18.000', $this->onShelf($roll, $a));

        try {
            $req->approve($big['id'], $this->user('1'));
            $this->fail('Self approval');
        } catch (Conflict $e) {
            $this->assertSame('SELF_APPROVAL', $e->errorCode());
        }
        $req->approve($big['id'], $this->user('2'), 'Verified');
        $this->assertSame(['5.000', '5.000'], [$this->onShelf($roll, $a), $this->level($roll)['on_hand']]);

        $this->expectException(Conflict::class); // already decided
        $req->reject($big['id'], $this->user('2'), 'late');
    }

    public function test_an_adjustment_from_a_stale_screen_is_refused_and_decided_requests_are_frozen(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        $this->ops()->receive($roll, $this->mx, 20, $this->actor(), $a);
        $seen = $this->version($roll, $a);
        $this->ops()->receive($roll, $this->mx, 1, $this->actor(), $a);

        try {
            app(AdjustmentRequests::class)->request($roll, $a, 15, $seen, 'x', $this->user('1'));
            $this->fail('Stale');
        } catch (Conflict $e) {
            $this->assertSame('STALE_VERSION', $e->errorCode());
        }

        $id = app(AdjustmentRequests::class)->request($roll, $a, 1, $this->version($roll, $a), 'x', $this->user('1'))['id'];
        $this->expectException(QueryException::class);
        DB::table('stock_adjustment_requests')->where('id', $id)->update(['delta' => 1]);
    }

    public function test_the_books_cannot_give_up_what_customers_reserved(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        $this->ops()->receive($roll, $this->mx, 10, $this->actor(), $a);
        app(ReservationService::class)->reserve('cart', 'cart-1', [['product_id' => $roll, 'quantity' => 8]], 15);

        $this->expectException(InsufficientStock::class);
        app(AdjustmentRequests::class)->request($roll, $a, 7, $this->version($roll, $a), 'lost 3', $this->user('1')); // -3, only 2 are free
    }

    public function test_a_blind_count_keeps_what_moved_while_counting_and_a_second_different_count_waits(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        $this->ops()->receive($roll, $this->mx, 10, $this->actor(), $a);
        $counts = app(CountSessions::class);
        $session = $counts->open($this->mx, 'Weekly', $this->user('1'));

        // 3 units are picked after the session starts but before the count is typed in
        app(LocationLedger::class)->record($roll, $a, 'pick', 3, $this->actor());
        $first = $counts->submit($session, $roll, $a, 9, $this->user('1'), 'c-1'); // they found 9 where the system said 10 when it began
        $this->assertSame('confirmed', $first['status']);
        $this->assertSame('6.000', $this->onShelf($roll, $a), '9 counted - 3 picked meanwhile = 6, not 9');

        $same = $counts->submit($session, $roll, $a, 9, $this->user('2'));
        $this->assertSame('confirmed', $same['status']);
        $this->assertNull($same['movement']);

        $other = $counts->submit($session, $roll, $a, 7, $this->user('3'));
        $this->assertSame('in_review', $other['status']);
        $this->assertSame('6.000', $this->onShelf($roll, $a));

        try {
            $counts->close($session, $this->user('1'));
            $this->fail('Open reviews');
        } catch (Conflict $e) {
            $this->assertSame('COUNTS_IN_REVIEW', $e->errorCode());
        }
        $counts->review($other['id'], true, $this->user('9'));
        $this->assertSame('4.000', $this->onShelf($roll, $a), '7 counted - 3 picked meanwhile');
        $counts->close($session, $this->user('1'));

        $this->assertSame(2, DB::table('count_entries')->where('status', 'confirmed')->whereNotNull('movement_ulid')->count(), 'first and the approved second count');
        $this->assertSame(2, DB::table('location_movements')->where('type', 'count_adjust')->count());
    }

    public function test_a_closed_count_refuses_more_entries_and_the_same_tap_twice_counts_once(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        $this->ops()->receive($roll, $this->mx, 10, $this->actor(), $a);
        $counts = app(CountSessions::class);
        $session = $counts->open($this->mx, 'Quick', $this->user('1'));
        $one = $counts->submit($session, $roll, $a, 8, $this->user('1'), 'tap');
        $two = $counts->submit($session, $roll, $a, 8, $this->user('1'), 'tap');
        $this->assertSame($one['id'], $two['id']);
        $this->assertSame(1, DB::table('count_entries')->count());
        $counts->close($session, $this->user('1'));

        $this->expectException(Conflict::class);
        $counts->submit($session, $roll, $a, 8, $this->user('1'));
    }

    public function test_after_every_operation_the_ledgers_reconcile(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        $this->ops()->receive($roll, $this->mx, 25, $this->actor(), $a);
        $this->ops()->writeOff($roll, $a, 2, $this->actor(), 'Frayed');
        $counts = app(CountSessions::class);
        $s = $counts->open($this->mx, 'S', $this->user('1'));
        $counts->submit($s, $roll, $a, 22, $this->user('1'));

        $this->assertSame('22.000', $this->onShelf($roll, $a));
        $this->assertSame('22.000', $this->level($roll)['on_hand']);
        $report = app(LocationReconciler::class)->check();
        $this->assertTrue($report['ok']);
        $this->assertSame([], $report['variance'], 'books and shelves agree when everything went through the warehouse');
        $this->assertTrue(app(InventoryReconciler::class)->check()['ok']);
    }
}
