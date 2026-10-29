<?php

declare(strict_types=1);

namespace Cronos\Notifications\Infrastructure\Console;

use Cronos\Notifications\Domain\Ports\NotificationLog;
use Cronos\Notifications\Infrastructure\Jobs\SendNotificationJob;
use Illuminate\Console\Command;

final class SweepNotificationsCommand extends Command
{
    protected $signature = 'notifications:sweep';

    protected $description = 'Re-queues notifications that were logged but never delivered (a lost job, a restarted worker)';

    public function handle(NotificationLog $log): int
    {
        $n = 0;
        foreach ($log->stale(new \DateTimeImmutable('-10 minutes'), 200) as $row) {
            SendNotificationJob::dispatch($row['id']);
            $log->update($row['id'], []); // touch: do not re-queue it again on the next sweep
            $n++;
        }
        $this->info("Re-queued: {$n}");

        return self::SUCCESS;
    }
}
