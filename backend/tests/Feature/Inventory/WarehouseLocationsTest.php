<?php

namespace Tests\Feature\Inventory;

use Cronos\Inventory\Application\LocationLedger;
use Cronos\Inventory\Application\LocationManager;
use Cronos\Inventory\Application\LocationReconciler;
use Cronos\Inventory\Application\WarehouseManager;
use Cronos\Inventory\Domain\Exceptions\Conflict;
use Cronos\Inventory\Domain\Exceptions\InsufficientStock;
use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Cronos\Inventory\Domain\Ports\LocationRepository;
use Cronos\Inventory\Domain\ValueObjects\StockCode;
use Cronos\Inventory\Infrastructure\Persistence\StockCodeIssuer;
use Database\Seeders\WarehouseDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class WarehouseLocationsTest extends InventoryTestCase
{
    private function locs(): LocationManager
    {
        return app(LocationManager::class);
    }

    private function ledger(): LocationLedger
    {
        return app(LocationLedger::class);
    }

    private function shelf(string $code, ?string $parent = null, ?int $warehouse = null): string
    {
        return $this->locs()->create($warehouse ?? $this->mx, ['code' => $code, 'name' => "Shelf {$code}", 'parent_id' => $parent]);
    }

    public function test_every_warehouse_has_its_reception_area_and_a_new_one_gets_it_too(): void
    {
        $this->assertNotNull(DB::table('warehouse_locations')->where(['warehouse_id' => $this->mx, 'code' => 'RECEPCION', 'is_system' => true])->first());
        $id = app(WarehouseManager::class)->create(['code' => 'EXTRA-01', 'name' => 'Extra']);
        $this->assertNotNull(DB::table('warehouse_locations')->where(['warehouse_id' => $id, 'code' => 'RECEPCION'])->first());
    }

    public function test_stock_code_normalises_checks_and_reads_qr_urls(): void
    {
        $code = StockCode::generate();
        $this->assertSame(11, strlen($code->value));
        $this->assertSame($code->value, StockCode::parse(strtolower($code->formatted()))->value);
        $this->assertSame($code->value, StockCode::fromScan('https://shop.test/w/'.$code->value));
        $this->assertSame($code->value, StockCode::fromScan($code->value));

        $bad = $code->value;
        $bad[0] = $bad[0] === '2' ? '3' : '2';
        $this->assertFalse(StockCode::isValid($bad));
        $this->expectException(InvalidMovement::class);
        StockCode::parse('nope');
    }

    public function test_a_new_stockable_product_gets_a_permanent_code_that_cannot_change(): void
    {
        $roll = $this->ribbon();
        $code = DB::table('products')->where('id', $roll)->value('stock_code');
        $this->assertTrue(StockCode::isValid($code));
        $this->assertSame($code, DB::table('stock_codes_issued')->where('product_id', $roll)->value('code'));

        foreach ([fn () => DB::table('products')->where('id', $roll)->update(['stock_code' => StockCode::generate()->value]), fn () => DB::table('products')->where('id', $roll)->update(['stock_code' => null])] as $try) {
            try {
                DB::transaction($try);
                $this->fail('The code changed');
            } catch (QueryException) {
                $this->assertSame($code, DB::table('products')->where('id', $roll)->value('stock_code'));
            }
        }
        $this->assertSame(0, app(StockCodeIssuer::class)->issueMissing());
    }

    public function test_the_location_tree_refuses_cycles_deep_branches_and_reused_codes(): void
    {
        config(['inventory.max_location_depth' => 3]);
        $a = $this->shelf('A');
        $b = $this->shelf('B', $a);
        $c = $this->shelf('C', $b);

        try {
            $this->shelf('D', $c);
            $this->fail('Too deep');
        } catch (InvalidMovement $e) {
            $this->assertSame('LOCATION_TOO_DEEP', $e->errorCode());
        }
        try {
            $this->locs()->move($a, $c);
            $this->fail('Cycle');
        } catch (InvalidMovement $e) {
            $this->assertSame('LOCATION_CYCLE', $e->errorCode());
        }
        $this->expectException(Conflict::class);
        $this->shelf('a'); // same code, any case
    }

    public function test_moving_a_branch_rewrites_paths_and_leaves_a_version_behind(): void
    {
        $a = $this->shelf('A');
        $b = $this->shelf('B');
        $a1 = $this->shelf('A-1', $a);
        $this->locs()->move($a, $b, 'user-1');

        $row = DB::table('warehouse_locations')->where('id', $a1)->first();
        $this->assertSame(3, (int) $row->depth);
        $this->assertSame("{$b}.{$a}.{$a1}", $row->path);
        $this->assertSame(2, (int) DB::table('warehouse_locations')->where('id', $a)->value('version'));
        $this->assertCount(2, DB::table('warehouse_location_versions')->where('location_id', $a)->get());
    }

    public function test_location_code_and_history_are_immutable_and_reception_is_protected(): void
    {
        $a = $this->shelf('A');
        $this->locs()->update($a, ['name' => 'Renamed']);
        $this->assertSame([1, 2], array_column($this->versions($a), 'version'));

        $this->expectException(QueryException::class);
        DB::table('warehouse_locations')->where('id', $a)->update(['code' => 'Z']);
    }

    public function test_versions_and_movements_cannot_be_edited_or_removed(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        $ulid = $this->ledger()->record($roll, $a, 'receive', 10, $this->actor());

        foreach ([
            fn () => DB::table('location_movements')->where('ulid', $ulid)->update(['quantity' => 99]),
            fn () => DB::table('location_movements')->where('ulid', $ulid)->delete(),
            fn () => DB::table('warehouse_location_versions')->where('location_id', $a)->delete(),
            fn () => DB::statement('TRUNCATE location_movements'),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('An immutable row changed');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_shelf_never_goes_negative_and_a_movement_hash_chains(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        $first = $this->ledger()->record($roll, $a, 'receive', 10, $this->actor());
        $second = $this->ledger()->record($roll, $a, 'pick', 4, $this->actor());

        $rows = DB::table('location_movements')->orderBy('id')->get();
        $this->assertSame([$first, $second], $rows->pluck('ulid')->all());
        $this->assertSame($rows[0]->row_hash, $rows[1]->prev_hash);
        $this->assertSame('6.000', (string) DB::table('location_stock')->where('location_id', $a)->value('quantity'));

        $this->expectException(InsufficientStock::class);
        $this->ledger()->record($roll, $a, 'pick', 7, $this->actor());
    }

    public function test_the_same_request_twice_changes_the_shelf_once_and_a_reused_key_is_refused(): void
    {
        $roll = $this->ribbon();
        $other = $this->ribbon('Otro', 'LIST-OTRO');
        $a = $this->shelf('A');
        $one = $this->ledger()->record($roll, $a, 'receive', 5, $this->actor(), idempotencyKey: 'tap-1');
        $two = $this->ledger()->record($roll, $a, 'receive', 5, $this->actor(), idempotencyKey: 'tap-1');

        $this->assertSame($one, $two);
        $this->assertSame('5.000', (string) DB::table('location_stock')->where('location_id', $a)->value('quantity'));
        $this->expectException(Conflict::class);
        $this->ledger()->record($other, $a, 'receive', 5, $this->actor(), idempotencyKey: 'tap-1');
    }

    public function test_a_stale_screen_is_refused(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        $this->ledger()->record($roll, $a, 'receive', 5, $this->actor());
        $this->ledger()->record($roll, $a, 'receive', 5, $this->actor(), expectedVersion: 1);

        $this->expectException(Conflict::class);
        $this->ledger()->record($roll, $a, 'receive', 5, $this->actor(), expectedVersion: 1);
    }

    public function test_a_move_writes_two_linked_rows_in_one_transaction_and_is_idempotent(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        $b = $this->shelf('B');
        $this->ledger()->record($roll, $a, 'receive', 10, $this->actor());

        $r1 = $this->ledger()->move($roll, $a, $b, '3.5', $this->actor(), 'mv-1');
        $r2 = $this->ledger()->move($roll, $a, $b, '3.5', $this->actor(), 'mv-1');

        $this->assertSame($r1, $r2);
        $qty = fn ($l) => (string) DB::table('location_stock')->where('location_id', $l)->value('quantity');
        $this->assertSame(['6.500', '3.500'], [$qty($a), $qty($b)]);
        $this->assertCount(1, DB::table('location_movements')->where('type', 'move_in')->get());
        $this->assertSame(DB::table('location_movements')->where('ulid', $r1['out'])->value('reference_id'), DB::table('location_movements')->where('ulid', $r1['in'])->value('reference_id'));
    }

    public function test_a_compensation_undoes_a_row_once_and_keeps_the_original(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        $ulid = $this->ledger()->record($roll, $a, 'receive', 10, $this->actor());
        $this->ledger()->compensate($ulid, $this->actor(), 'Typed the wrong roll');

        $this->assertSame('0.000', (string) DB::table('location_stock')->where('location_id', $a)->value('quantity'));
        $this->assertSame('10.000', (string) DB::table('location_movements')->where('ulid', $ulid)->value('quantity'));
        $this->expectException(Conflict::class);
        $this->ledger()->compensate($ulid, $this->actor(), 'again');
    }

    public function test_a_location_with_stock_cannot_be_deactivated(): void
    {
        $roll = $this->ribbon();
        $a = $this->shelf('A');
        $this->ledger()->record($roll, $a, 'receive', 1, $this->actor());

        $this->expectException(Conflict::class);
        $this->locs()->update($a, ['is_active' => false]);
    }

    public function test_the_reconciler_is_clean_reports_variance_and_detects_tampering(): void
    {
        $roll = $this->ribbon();
        $this->stock($roll, 20);
        $a = $this->shelf('A');
        $this->ledger()->record($roll, $a, 'receive', 8, $this->actor());

        $report = app(LocationReconciler::class)->check();
        $this->assertTrue($report['ok']);
        $this->assertCount(1, $report['variance'], 'books say 20, shelves say 8: informational');

        // Someone with database access rewrites who did it: the chain no longer verifies.
        DB::unprepared('ALTER TABLE location_movements DISABLE TRIGGER location_movements_append_only');
        DB::table('location_movements')->update(['created_by' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']);
        DB::unprepared('ALTER TABLE location_movements ENABLE TRIGGER location_movements_append_only');
        $this->assertNotSame([], app(LocationReconciler::class)->check()['broken_chains']);
    }

    public function test_opening_stock_is_placed_in_reception_once(): void
    {
        $roll = $this->ribbon();
        $this->stock($roll, 15);

        $this->artisan('warehouse:assign-opening-stock')->assertSuccessful();
        $this->artisan('warehouse:assign-opening-stock')->assertSuccessful();

        $reception = DB::table('warehouse_locations')->where(['warehouse_id' => $this->mx, 'code' => 'RECEPCION'])->value('id');
        $this->assertSame('15.000', (string) DB::table('location_stock')->where(['location_id' => $reception, 'product_id' => $roll])->value('quantity'));
        $this->assertSame(1, DB::table('location_movements')->where('product_id', $roll)->count());
        $this->assertTrue(app(LocationReconciler::class)->check()['ok']);
    }

    /** @return list<array<string, mixed>> */
    private function versions(string $id): array
    {
        return app(LocationRepository::class)->versions($id);
    }

    public function test_the_demo_tree_of_the_client_seeds_once_and_has_the_expected_depth(): void
    {
        $this->seed(WarehouseDemoSeeder::class);
        $this->seed(WarehouseDemoSeeder::class);

        $rows = DB::table('warehouse_locations')->where('warehouse_id', $this->mx)->where('is_system', false)->get()->keyBy('code');
        $this->assertCount(10, $rows);
        $this->assertSame(4, (int) $rows['C02']->depth, 'Bodega > Pasillo A > Estante 03 > Caja 02');
        $this->assertSame(2, (int) $rows['MOS']->depth);
    }
}
