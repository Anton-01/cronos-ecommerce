<?php

namespace Tests\Feature\Inventory;

use Cronos\Catalog\Application\CatalogReader;
use Cronos\Inventory\Application\Actor;
use Cronos\Inventory\Application\InventoryReconciler;
use Cronos\Inventory\Application\StockAdjuster;
use Cronos\Inventory\Application\StockLedger;
use Cronos\Inventory\Application\WarehouseManager;
use Cronos\Inventory\Domain\Events\StockLow;
use Cronos\Inventory\Domain\Exceptions\Conflict;
use Cronos\Inventory\Domain\Exceptions\InsufficientStock;
use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Cronos\Inventory\Domain\Ports\WarehouseRepository;
use Cronos\Inventory\Domain\ValueObjects\MovementType;
use Cronos\Shared\Domain\ClientContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class KardexTest extends InventoryTestCase
{
    private function ledger(): StockLedger
    {
        return app(StockLedger::class);
    }

    public function test_a_purchase_enters_stock_and_every_movement_chains_to_the_previous_one(): void
    {
        $roll = $this->ribbon();
        $first = $this->stock($roll, 100);
        $second = $this->ledger()->record($roll, $this->mx, MovementType::SALE, '0.5', $this->actor());

        $this->assertSame(['100.000', '0.000', '100.000'], array_values($this->level($roll)) === ['99.500', '0.000', '99.500'] ? ['100.000', '0.000', '100.000'] : ['x']);
        $this->assertSame('99.500', $this->level($roll)['on_hand']);
        $this->assertSame('99.500', $this->productStock($roll), 'products.stock_quantity follows the ledger');

        $rows = DB::table('inventory_movements')->orderBy('id')->get();
        $this->assertSame([$first, $second], $rows->pluck('ulid')->all());
        $this->assertSame(['100.000', '99.500'], $rows->pluck('balance_after')->map(fn ($v) => (string) $v)->all());
        $this->assertNull($rows[0]->prev_hash);
        $this->assertSame($rows[0]->row_hash, $rows[1]->prev_hash);
        $this->assertSame('-0.500', (string) $rows[1]->quantity, 'a sale leaves: the sign comes from the type');
    }

    public function test_decimal_quantities_are_exact_and_extra_precision_is_refused(): void
    {
        $roll = $this->ribbon();
        $this->ledger()->record($roll, $this->mx, MovementType::PURCHASE, '0.1', $this->actor());
        $this->ledger()->record($roll, $this->mx, MovementType::PURCHASE, '0.2', $this->actor());
        $this->assertSame('0.300', $this->level($roll)['on_hand'], '0.1 + 0.2 is exactly 0.3');

        $this->expectException(InvalidMovement::class);
        $this->ledger()->record($roll, $this->mx, MovementType::PURCHASE, '1.0005', $this->actor());
    }

    public function test_the_type_decides_the_direction_and_adjustments_must_say_which_way(): void
    {
        $bow = $this->piece();
        $this->stock($bow, 10);

        foreach ([
            fn () => $this->ledger()->record($bow, $this->mx, MovementType::ADJUSTMENT, 1, $this->actor()),
            fn () => $this->ledger()->record($bow, $this->mx, MovementType::PURCHASE, 1, $this->actor(), increase: false),
            fn () => $this->ledger()->record($bow, $this->mx, MovementType::SALE, 0, $this->actor()),
            fn () => $this->ledger()->record($bow, $this->mx, 'gift', 1, $this->actor()),
        ] as $bad) {
            try {
                $bad();
                $this->fail('expected the movement to be refused');
            } catch (InvalidMovement) {
                $this->addToAssertionCount(1);
            }
        }
        $this->ledger()->record($bow, $this->mx, MovementType::ADJUSTMENT, 3, $this->actor(), increase: false);
        $this->assertSame('7.000', $this->level($bow)['on_hand']);
    }

    public function test_you_cannot_sell_what_is_not_there_and_nothing_is_recorded_when_you_try(): void
    {
        $bow = $this->piece();
        $this->stock($bow, 5);

        try {
            $this->ledger()->record($bow, $this->mx, MovementType::SALE, 6, $this->actor());
            $this->fail('expected insufficient stock');
        } catch (InsufficientStock $e) {
            $this->assertSame(['6.000', '5.000'], [$e->requested, $e->available]);
        }
        $this->assertSame(1, DB::table('inventory_movements')->count());
        $this->assertSame('5.000', $this->level($bow)['on_hand']);
    }

    public function test_a_product_that_accepts_backorders_can_go_negative_and_shows_zero_stock(): void
    {
        $id = $this->piece(sku: 'BACK', extra: ['allow_backorder' => true]);
        $this->stock($id, 2);
        $this->ledger()->record($id, $this->mx, MovementType::SALE, 5, $this->actor());

        $this->assertSame('-3.000', $this->level($id)['on_hand']);
        $this->assertSame('0.000', $this->productStock($id), 'oversold stock is never shown as negative');
    }

    public function test_variable_grouped_and_unmanaged_products_keep_no_stock_of_their_own(): void
    {
        $unmanaged = $this->piece(name: 'Sin control', sku: 'NOSTOCK', extra: ['manage_stock' => false]);
        $variable = $this->variableRibbon($this->colorAndWidth());

        foreach ([$unmanaged, $variable] as $id) {
            try {
                $this->stock($id, 1);
                $this->fail('expected the product to be refused');
            } catch (InvalidMovement $e) {
                $this->assertSame('PRODUCT_DOES_NOT_TRACK_STOCK', $e->errorCode());
            }
        }
    }

    public function test_the_kardex_cannot_be_edited_deleted_or_emptied_and_the_database_checks_the_signs(): void
    {
        $bow = $this->piece();
        $ulid = $this->stock($bow, 10);

        foreach ([
            fn () => DB::table('inventory_movements')->where('ulid', $ulid)->update(['quantity' => 999]),
            fn () => DB::table('inventory_movements')->where('ulid', $ulid)->delete(),
            fn () => DB::statement('TRUNCATE inventory_movements'),
        ] as $tamper) {
            try {
                DB::transaction($tamper);
                $this->fail('the Kardex accepted a change');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(QueryException::class);
        DB::table('inventory_movements')->insert([
            'ulid' => str_repeat('a', 26), 'product_id' => $bow, 'warehouse_id' => $this->mx, 'quantity' => -1, 'type' => 'purchase', 'balance_after' => 9, 'row_hash' => str_repeat('0', 64),
        ]);
    }

    public function test_every_movement_records_who_where_and_on_which_clock(): void
    {
        $bow = $this->piece();
        $actor = new Actor(null, new ClientContext('187.190.10.20', 'phpunit', 'MX', 'America/Mexico_City'));
        $this->ledger()->record($bow, $this->mx, MovementType::PURCHASE, 4, $actor, unitCost: '12.5', referenceType: 'purchase_order', referenceId: 'PO-77', note: 'Primera compra');

        $row = DB::table('inventory_movements')->first();
        $this->assertSame(['187.190.10.20', 'MX', 'America/Mexico_City', 'purchase_order', 'PO-77', '12.5000'], [$row->ip_address, $row->country_code, $row->timezone, $row->reference_type, $row->reference_id, (string) $row->unit_cost]);
    }

    public function test_transfers_move_stock_between_warehouses_in_two_linked_movements_or_not_at_all(): void
    {
        $roll = $this->ribbon();
        $this->stock($roll, 50);
        $adjuster = app(StockAdjuster::class);

        $legs = $adjuster->transfer($roll, $this->mx, $this->us, '12.5', $this->actor(), 'Surtir EE. UU.');
        $this->assertSame(['37.500', '12.500'], [$this->level($roll, $this->mx)['on_hand'], $this->level($roll, $this->us)['on_hand']]);
        $this->assertSame('50.000', $this->productStock($roll), 'the product total did not change');
        $out = DB::table('inventory_movements')->where('ulid', $legs['out'])->first();
        $in = DB::table('inventory_movements')->where('ulid', $legs['in'])->first();
        $this->assertSame([$out->reference_id, 'transfer_out', 'transfer_in'], [$in->reference_id, $out->type, $in->type]);

        try {
            $adjuster->transfer($roll, $this->us, $this->mx, 99, $this->actor());
            $this->fail('expected insufficient stock');
        } catch (InsufficientStock) {
            $this->assertSame('12.500', $this->level($roll, $this->us)['on_hand']);
            $this->assertSame(3, DB::table('inventory_movements')->count(), 'no half-done transfer left behind');
        }
    }

    public function test_a_physical_count_records_only_the_difference(): void
    {
        $bow = $this->piece();
        $this->stock($bow, 100);
        $adjuster = app(StockAdjuster::class);

        $this->assertNull($adjuster->setCount($bow, $this->mx, 100, $this->actor()), 'a matching count writes nothing');
        $ulid = $adjuster->setCount($bow, $this->mx, 92, $this->actor(), 'Conteo de octubre');
        $this->assertSame('92.000', $this->level($bow)['on_hand']);
        $row = DB::table('inventory_movements')->where('ulid', $ulid)->first();
        $this->assertSame(['adjustment', '-8.000', 'count'], [$row->type, (string) $row->quantity, $row->reference_type]);

        $adjuster->setCount($bow, $this->mx, 95, $this->actor());
        $this->assertSame('95.000', $this->level($bow)['on_hand']);
    }

    public function test_a_mistake_is_compensated_by_a_new_movement_once_and_only_once(): void
    {
        $bow = $this->piece();
        $wrong = $this->stock($bow, 100); // typed 100 instead of 10
        $adjuster = app(StockAdjuster::class);

        $fix = $adjuster->compensate($wrong, 'Se capturó 100 en lugar de 10', $this->actor());
        $this->assertSame('0.000', $this->level($bow)['on_hand']);
        $row = DB::table('inventory_movements')->where('ulid', $fix)->first();
        $this->assertSame(['adjustment', '-100.000', 'movement', $wrong], [$row->type, (string) $row->quantity, $row->reference_type, $row->reference_id]);
        $this->assertSame(2, DB::table('inventory_movements')->count(), 'the original is still there, untouched');

        foreach ([[$wrong, 'ALREADY_COMPENSATED'], [$fix, 'ALREADY_COMPENSATING']] as [$ulid, $code]) {
            try {
                $adjuster->compensate($ulid, 'otra vez', $this->actor());
                $this->fail("expected {$code}");
            } catch (Conflict $e) {
                $this->assertSame($code, $e->errorCode());
            }
        }

        $this->expectException(InvalidMovement::class);
        $adjuster->compensate($this->stock($bow, 5), '  ', $this->actor());
    }

    public function test_a_compensation_that_would_take_stock_that_was_already_sold_is_refused(): void
    {
        $bow = $this->piece();
        $purchase = $this->stock($bow, 10);
        $this->ledger()->record($bow, $this->mx, MovementType::SALE, 8, $this->actor());

        $this->expectException(InsufficientStock::class);
        app(StockAdjuster::class)->compensate($purchase, 'error', $this->actor());
    }

    public function test_low_stock_is_announced_when_available_reaches_the_threshold(): void
    {
        Event::fake([StockLow::class]);
        $bow = $this->piece(extra: ['low_stock_threshold' => '5']);
        $this->stock($bow, 8);
        Event::assertNotDispatched(StockLow::class);

        $this->ledger()->record($bow, $this->mx, MovementType::SALE, 3, $this->actor());

        Event::assertDispatched(StockLow::class, fn (StockLow $e) => $e->productId === $bow && $e->available === '5.000' && $e->threshold === '5.000');
    }

    public function test_the_storefront_availability_follows_the_kardex_without_stale_cache(): void
    {
        $bow = $this->published($this->piece(name: 'Moño de temporada', sku: 'TEMP'));
        $status = fn () => app(CatalogReader::class)->product('es-MX', 'mono-de-temporada')['stockStatus'];

        $this->assertSame('OUT_OF_STOCK', $status());
        $this->stock($bow, 10);
        $this->assertSame('IN_STOCK', $status(), 'the cached page was purged by the stock change');
        $this->ledger()->record($bow, $this->mx, MovementType::SALE, 10, $this->actor());
        $this->assertSame('OUT_OF_STOCK', $status());
    }

    public function test_reconcile_finds_drift_in_derived_data_and_repairs_it_from_the_kardex(): void
    {
        $bow = $this->piece();
        $this->stock($bow, 40);
        $reconciler = app(InventoryReconciler::class);
        $this->assertTrue($reconciler->check()['ok']);

        DB::table('stock_levels')->where('product_id', $bow)->update(['on_hand' => 41]);
        DB::table('products')->where('id', $bow)->update(['stock_quantity' => 7]);

        $report = $reconciler->check();
        $this->assertFalse($report['ok']);
        $this->assertCount(1, $report['level_mismatches']);
        $this->assertCount(1, $report['product_mismatches']);
        $this->assertSame([], $report['broken_chains'], 'the Kardex itself is fine');

        $this->artisan('inventory:reconcile')->assertFailed();
        $this->artisan('inventory:reconcile', ['--repair' => true])->assertSuccessful();
        $this->assertSame(['40.000', '40.000'], [$this->level($bow)['on_hand'], $this->productStock($bow)]);
    }

    public function test_reconcile_detects_a_tampered_kardex_and_refuses_to_paper_over_it(): void
    {
        $bow = $this->piece();
        $this->stock($bow, 40);
        $this->ledger()->record($bow, $this->mx, MovementType::SALE, 5, $this->actor());

        // Someone with the owner's rights switches the guard off and rewrites history.
        DB::unprepared('ALTER TABLE inventory_movements DISABLE TRIGGER inventory_movements_append_only');
        DB::table('inventory_movements')->where('type', 'sale')->update(['quantity' => -1]);
        DB::unprepared('ALTER TABLE inventory_movements ENABLE TRIGGER inventory_movements_append_only');

        $report = app(InventoryReconciler::class)->check();
        $this->assertFalse($report['ok']);
        $this->assertNotEmpty($report['broken_chains']);
        $this->artisan('inventory:reconcile', ['--repair' => true])->assertFailed();
    }

    public function test_warehouses_protect_the_default_and_anything_with_history(): void
    {
        $manager = app(WarehouseManager::class);
        $bow = $this->piece();
        $this->stock($bow, 1, $this->us);

        foreach ([
            fn () => $manager->delete($this->mx),
            fn () => $manager->update($this->mx, ['code' => 'MX-01', 'name' => 'x', 'is_active' => false]),
            fn () => $manager->delete($this->us),
            fn () => $manager->create(['code' => 'mx-01', 'name' => 'dup']),
        ] as $bad) {
            try {
                $bad();
                $this->fail('expected a conflict');
            } catch (Conflict) {
                $this->addToAssertionCount(1);
            }
        }

        $extra = $manager->create(['code' => 'tj-01', 'name' => 'Tijuana', 'market_code' => 'MX']);
        $manager->delete($extra);
        $this->assertNull(app(WarehouseRepository::class)->find($extra));
    }
}
