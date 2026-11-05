<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Console;

use Cronos\Inventory\Application\AlertRaiser;
use Cronos\Inventory\Application\LocationReconciler;
use Cronos\Inventory\Domain\ValueObjects\AlertProcess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ReconcileLocationsCommand extends Command
{
    protected $signature = 'inventory:reconcile-locations {--repair : Rebuild the per-shelf quantities from the location ledger}';

    protected $description = 'Check the per-shelf stock against the immutable location ledger and verify its hash chains';

    public function handle(AlertRaiser $alerts, LocationReconciler $reconciler): int
    {
        $report = $reconciler->check();
        foreach ($report['variance'] as $line) {
            $this->line("[pending put-away] {$line}");
        }
        foreach (['broken_chains' => 'LOCATION LEDGER TAMPERED', 'stock_mismatches' => 'shelf', 'untracked_codes' => 'product without stock code'] as $key => $label) {
            foreach ($report[$key] as $line) {
                $this->error("[{$label}] {$line}");
            }
        }
        if ($report['ok']) {
            $this->info('Location ledger is consistent.');

            return self::SUCCESS;
        }
        $alerts->raise(AlertProcess::INTEGRITY_FAILURE, 'Falla de integridad: libro de anaqueles', 'La conciliación nocturna encontró diferencias. Revisa el detalle con el comando de conciliación antes de seguir operando.', 'integrity:locations:'.date('Y-m-d'));
        Log::critical('Location reconciliation found differences', $report);
        if ($report['broken_chains'] !== []) {
            $this->error('The ledger itself fails verification: derived data cannot repair that.');

            return self::FAILURE;
        }
        if ($this->option('repair')) {
            $this->warn('Rebuilt '.$reconciler->repairDerived().' shelf row(s) from the ledger.');

            return $reconciler->check()['stock_mismatches'] === [] ? self::SUCCESS : self::FAILURE;
        }

        return self::FAILURE;
    }
}
