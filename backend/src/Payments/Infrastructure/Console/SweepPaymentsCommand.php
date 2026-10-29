<?php

declare(strict_types=1);

namespace Cronos\Payments\Infrastructure\Console;

use Cronos\Payments\Application\PaymentReconciler;
use Illuminate\Console\Command;

final class SweepPaymentsCommand extends Command
{
    protected $signature = 'payments:sweep {--reconcile : Also ask providers about payments that should have finished}';

    protected $description = 'Process notifications that are due, and (with --reconcile) look up payments whose webhook never arrived';

    public function handle(PaymentReconciler $reconciler): int
    {
        $this->info('Notifications processed: '.$reconciler->sweepWebhooks());
        if ($this->option('reconcile')) {
            $r = $reconciler->run();
            $this->info("Payments checked: {$r['checked']}, changed: {$r['changed']}, abandoned: {$r['abandoned']}, errors: {$r['errors']}");
        }

        return self::SUCCESS;
    }
}
