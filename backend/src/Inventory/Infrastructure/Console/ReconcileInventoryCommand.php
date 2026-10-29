<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Console;

use Cronos\Inventory\Application\InventoryReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ReconcileInventoryCommand extends Command
{
    protected $signature = 'inventory:reconcile {--repair : Rebuild the derived numbers (levels, product stock) from the Kardex}';

    protected $description = 'Check that stock levels agree with the immutable Kardex and that the Kardex hash chains are intact';

    public function handle(InventoryReconciler $reconciler): int
    {
        $report = $reconciler->check();
        if ($report['ok']) {
            $this->info('Inventory is consistent.');

            return self::SUCCESS;
        }

        foreach (['broken_chains' => 'KARDEX TAMPERED', 'level_mismatches' => 'level', 'reserved_mismatches' => 'reserved', 'product_mismatches' => 'product stock'] as $key => $label) {
            foreach ($report[$key] as $line) {
                $this->error("[{$label}] {$line}");
            }
        }
        Log::critical('Inventory reconciliation found differences', $report);

        if ($report['broken_chains'] !== []) {
            $this->error('The Kardex itself fails verification: derived data cannot repair that. Investigate before touching anything.');

            return self::FAILURE;
        }
        if ($this->option('repair')) {
            $this->warn('Rebuilt '.$reconciler->repairDerived().' derived row(s) from the Kardex.');

            return $reconciler->check()['ok'] ? self::SUCCESS : self::FAILURE;
        }

        return self::FAILURE;
    }
}
