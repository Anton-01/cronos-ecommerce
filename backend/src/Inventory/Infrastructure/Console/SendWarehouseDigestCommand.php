<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure\Console;

use Cronos\Inventory\Application\DailyDigest;
use Illuminate\Console\Command;

final class SendWarehouseDigestCommand extends Command
{
    protected $signature = 'warehouse:digest';

    protected $description = 'Send the daily warehouse summary to everyone subscribed to it (once per day)';

    public function handle(DailyDigest $digest): int
    {
        $this->info($digest->send() === null ? 'Already sent today.' : 'Digest sent.');

        return self::SUCCESS;
    }
}
