<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Console;

use Cronos\Inventory\Application\Actor;
use Cronos\Inventory\Application\LocationLedger;
use Cronos\Inventory\Application\LocationManager;
use Cronos\Inventory\Domain\Exceptions\InvalidMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AssignOpeningStockCommand extends Command
{
    protected $signature = 'warehouse:assign-opening-stock {--dry-run : Only report what would be placed}';

    protected $description = 'Put the stock each warehouse already books into its RECEPCION area as opening balances (safe to repeat)';

    public function handle(LocationManager $locations, LocationLedger $ledger): int
    {
        $placed = 0;
        foreach (DB::table('warehouses')->whereNull('deleted_at')->pluck('id') as $warehouseId) {
            $reception = $this->option('dry-run') ? null : $locations->ensureReception((int) $warehouseId);
            $levels = DB::table('stock_levels')->where('warehouse_id', $warehouseId)->where('on_hand', '>', 0)->get(['product_id', 'on_hand']);
            foreach ($levels as $level) {
                // A product that already has shelf history in this warehouse is the warehouse team's now: never overwrite it.
                if (DB::table('location_movements')->where(['product_id' => $level->product_id, 'warehouse_id' => $warehouseId])->exists()) {
                    continue;
                }
                if ($this->option('dry-run')) {
                    $placed++;
                    $this->line("would place {$level->on_hand} of {$level->product_id} in warehouse {$warehouseId}");

                    continue;
                }
                try {
                    $ledger->record($level->product_id, $reception, 'initial', (string) $level->on_hand, Actor::system(), idempotencyKey: "opening:{$level->product_id}:{$warehouseId}", note: 'Opening balance');
                    $placed++;
                } catch (InvalidMovement $e) {
                    $this->warn("skipped {$level->product_id}: ".$e->getMessage());
                }
            }
        }
        $this->info(($this->option('dry-run') ? 'Would place ' : 'Placed ')."{$placed} opening balance(s).");

        return self::SUCCESS;
    }
}
