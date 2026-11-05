<?php

namespace Tests\Feature\Inventory;

use Cronos\Inventory\Application\InventoryReconciler;
use Cronos\Inventory\Application\LocationManager;
use Cronos\Inventory\Application\LocationReconciler;
use Cronos\Inventory\Application\PickLists;
use Cronos\Inventory\Application\WarehouseOperations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Real processes, one real database, everything committed: customers buying while the warehouse receives, moves, picks and adjusts the same
 * product. It runs on a throwaway database (created and dropped here) because the ledgers cannot be cleaned up after the fact, by design.
 */
#[Group('race')]
final class WarehouseRaceTest extends InventoryTestCase
{
    private string $raceDb = '';

    private string $original = '';

    public function refreshDatabase(): void
    {
        // No wrapping transaction: other processes must see what this test writes.
        $this->original = (string) config('database.connections.pgsql.database');
        $this->raceDb = 'cronos_race_'.getmypid();
        $this->admin("DROP DATABASE IF EXISTS {$this->raceDb}");
        $this->admin("CREATE DATABASE {$this->raceDb} TEMPLATE template0 ENCODING 'UTF8' LC_COLLATE 'C' LC_CTYPE 'C'");
        $this->useDatabase($this->raceDb);
        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->raceDb !== '') {
            $this->useDatabase($this->original);
            $this->admin("DROP DATABASE IF EXISTS {$this->raceDb} WITH (FORCE)");
        }
        parent::tearDown();
    }

    private function useDatabase(string $name): void
    {
        config(['database.connections.pgsql.database' => $name]);
        DB::purge('pgsql');
        DB::reconnect('pgsql');
    }

    private function admin(string $sql): void
    {
        $this->useDatabase('postgres');
        DB::connection('pgsql')->getPdo()->exec($sql);
        $this->useDatabase($this->original);
    }

    /** @param list<array<string, mixed>> $jobs @return list<array<string, mixed>> */
    private function race(array $jobs): array
    {
        $env = array_merge(getenv(), ['DB_DATABASE' => $this->raceDb]);
        $running = [];
        foreach ($jobs as $job) {
            $process = proc_open([PHP_BINARY, __DIR__.'/../../Support/warehouse_race_worker.php', json_encode($job)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            $running[] = [$process, $pipes];
        }
        $results = [];
        foreach ($running as $p) {
            $pipes = $p[1];
            $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            proc_close($p[0]);
            $results[] = json_decode(trim((string) strrchr("\n".trim($out), "\n")), true) ?? ['other' => [$out]];
        }

        return $results;
    }

    public function test_customers_keep_buying_while_the_warehouse_receives_moves_picks_and_adjusts(): void
    {
        $roll = $this->ribbon();
        $locs = app(LocationManager::class);
        $a = $locs->create($this->mx, ['code' => 'A', 'name' => 'A']);
        $b = $locs->create($this->mx, ['code' => 'B', 'name' => 'B']);
        app(WarehouseOperations::class)->receive($roll, $this->mx, 40, $this->actor(), $a);
        app(WarehouseOperations::class)->receive($roll, $this->mx, 10, $this->actor(), $b);
        $shelves = [$a, $b];
        $base = ['product' => $roll, 'warehouse' => $this->mx, 'shelves' => $shelves];

        $results = $this->race([
            $base + ['role' => 'buyer', 'name' => 'b1', 'rounds' => 30],
            $base + ['role' => 'buyer', 'name' => 'b2', 'rounds' => 30],
            $base + ['role' => 'buyer', 'name' => 'b3', 'rounds' => 30],
            $base + ['role' => 'receiver', 'name' => 'r1', 'rounds' => 20],
            $base + ['role' => 'mover', 'name' => 'm1', 'rounds' => 30],
            $base + ['role' => 'mover', 'name' => 'm2', 'rounds' => 30],
            $base + ['role' => 'adjuster', 'name' => 'j1', 'rounds' => 15],
            $base + ['role' => 'picker', 'name' => 'p1', 'rounds' => 15],
        ]);

        foreach ($results as $r) {
            $this->assertSame([], $r['other'] ?? [], 'unexpected failures: '.json_encode($r));
            $this->assertSame(0, $r['deadlocks'] ?? 0, 'a deadlock: '.json_encode($r));
        }

        // Money side: units sold = what buyers got; never more than what existed (no oversell), reservations all settled.
        $sold = (float) DB::table('inventory_movements')->where('product_id', $roll)->where('type', 'reservation_consume')->sum(DB::raw('-quantity'));
        $this->assertGreaterThan(0, $sold);
        $this->assertSame((float) ($results[0]['ok'] + $results[1]['ok'] + $results[2]['ok']), $sold, 'every purchase that was accepted is in the Kardex, none twice');
        $this->assertSame(0, DB::table('inventory_movements')->where('balance_after', '<', 0)->count(), 'the books were never below zero at any moment: nothing was oversold');
        $this->assertSame(0, DB::table('location_movements')->where('balance_after', '<', 0)->count());
        $level = DB::table('stock_levels')->where(['product_id' => $roll, 'warehouse_id' => $this->mx])->first();
        $this->assertGreaterThanOrEqual(0, (float) $level->on_hand);
        $this->assertSame('0.000', (string) $level->reserved);
        $this->assertSame(0, DB::table('inventory_reservations')->where('status', 'active')->count());

        // Both ledgers still add up and their chains verify.
        $books = app(InventoryReconciler::class)->check();
        $this->assertTrue($books['ok'], json_encode($books));
        $shelvesReport = app(LocationReconciler::class)->check();
        $this->assertTrue($shelvesReport['ok'], json_encode($shelvesReport));
        $this->assertSame(0, DB::table('location_stock')->where('quantity', '<', 0)->count());

        // What the books sold but the shelves have not yet handed over is exactly the open pick list.
        $pending = array_sum(array_map(fn ($l) => (float) $l['pending'], app(PickLists::class)->pending($this->mx, 1000)));
        $onShelves = (float) DB::table('location_stock')->where('product_id', $roll)->sum('quantity');
        $this->assertEqualsWithDelta((float) $level->on_hand + $pending, $onShelves, 0.0005, 'books + still-to-pick = shelves');
    }
}
