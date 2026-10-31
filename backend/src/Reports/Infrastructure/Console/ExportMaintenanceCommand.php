<?php

declare(strict_types=1);

namespace Cronos\Reports\Infrastructure\Console;

use Cronos\Reports\Application\ExportService;
use Illuminate\Console\Command;

final class ExportMaintenanceCommand extends Command
{
    protected $signature = 'exports:maintain';

    protected $description = 'Deletes expired export files and re-queues exports a dead worker left half done';

    public function handle(ExportService $exports): int
    {
        $this->info('Re-queued: '.$exports->recoverStuck().' · Expired: '.$exports->purge());

        return self::SUCCESS;
    }
}
