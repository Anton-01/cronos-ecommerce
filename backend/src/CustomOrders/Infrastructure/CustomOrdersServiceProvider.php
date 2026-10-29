<?php

declare(strict_types=1);

namespace Cronos\CustomOrders\Infrastructure;

use Cronos\CustomOrders\Domain\Ports\CustomOrderRepository;
use Cronos\CustomOrders\Infrastructure\Console\ExpireQuotesCommand;
use Cronos\CustomOrders\Infrastructure\Persistence\DatabaseCustomOrderRepository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class CustomOrdersServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [CustomOrderRepository::class => DatabaseCustomOrderRepository::class];

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireQuotesCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('custom-orders:expire')->dailyAt('03:20')->withoutOverlapping()->onOneServer();
        });
    }
}
