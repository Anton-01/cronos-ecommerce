<?php

declare(strict_types=1);

namespace Cronos\Audit\Infrastructure;

use Cronos\Audit\Infrastructure\Console\EnsureAuditPartitionsCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([EnsureAuditPartitionsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('audit:partitions --months=6')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
        });
    }
}
