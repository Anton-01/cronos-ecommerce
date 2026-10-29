<?php

declare(strict_types=1);

namespace Cronos\Audit\Infrastructure;

use Cronos\Audit\Infrastructure\Console\EnsureAuditPartitionsCommand;
use Cronos\Audit\Infrastructure\Persistence\GeoAwareAuditDriver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use OwenIt\Auditing\Contracts\Auditor;

final class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(Auditor::class)->extend('geo', fn () => new GeoAwareAuditDriver);

        if ($this->app->runningInConsole()) {
            $this->commands([EnsureAuditPartitionsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('audit:partitions --months=6')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
        });
    }
}
