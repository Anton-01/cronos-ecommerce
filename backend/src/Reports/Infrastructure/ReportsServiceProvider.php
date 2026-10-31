<?php

declare(strict_types=1);

namespace Cronos\Reports\Infrastructure;

use Cronos\Reports\Domain\Ports\ExportQueue;
use Cronos\Reports\Domain\Ports\ExportRecipients;
use Cronos\Reports\Domain\Ports\ExportRepository;
use Cronos\Reports\Domain\Ports\ExportSource;
use Cronos\Reports\Domain\Ports\ExportWriter;
use Cronos\Reports\Domain\Ports\ReportQueries;
use Cronos\Reports\Infrastructure\Console\ExportMaintenanceCommand;
use Cronos\Reports\Infrastructure\Exports\DatabaseExportSource;
use Cronos\Reports\Infrastructure\Exports\OpenSpoutExportWriter;
use Cronos\Reports\Infrastructure\Jobs\QueueExportRunner;
use Cronos\Reports\Infrastructure\Persistence\DatabaseExportRecipients;
use Cronos\Reports\Infrastructure\Persistence\DatabaseExportRepository;
use Cronos\Reports\Infrastructure\Persistence\DatabaseReportQueries;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class ReportsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ReportQueries::class => DatabaseReportQueries::class,
        ExportRepository::class => DatabaseExportRepository::class,
        ExportSource::class => DatabaseExportSource::class,
        ExportWriter::class => OpenSpoutExportWriter::class,
        ExportQueue::class => QueueExportRunner::class,
        ExportRecipients::class => DatabaseExportRecipients::class,
    ];

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExportMaintenanceCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('exports:maintain')->everyTenMinutes()->withoutOverlapping()->onOneServer();
        });
    }
}
