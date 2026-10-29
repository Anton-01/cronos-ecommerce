<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure;

use Cronos\Inventory\Application\Listeners\WatchLowStock;
use Cronos\Inventory\Domain\Events\StockChanged;
use Cronos\Inventory\Domain\Ports\InventoryQueries;
use Cronos\Inventory\Domain\Ports\ProductStockInfo;
use Cronos\Inventory\Domain\Ports\ReservationStore;
use Cronos\Inventory\Domain\Ports\StockStore;
use Cronos\Inventory\Domain\Ports\WarehouseRepository;
use Cronos\Inventory\Infrastructure\Console\ExpireReservationsCommand;
use Cronos\Inventory\Infrastructure\Console\ReconcileInventoryCommand;
use Cronos\Inventory\Infrastructure\Persistence\DatabaseInventoryQueries;
use Cronos\Inventory\Infrastructure\Persistence\DatabaseProductStockInfo;
use Cronos\Inventory\Infrastructure\Persistence\DatabaseReservationStore;
use Cronos\Inventory\Infrastructure\Persistence\DatabaseStockStore;
use Cronos\Inventory\Infrastructure\Persistence\EloquentWarehouseRepository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

final class InventoryServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        StockStore::class => DatabaseStockStore::class,
        ReservationStore::class => DatabaseReservationStore::class,
        WarehouseRepository::class => EloquentWarehouseRepository::class,
        ProductStockInfo::class => DatabaseProductStockInfo::class,
        InventoryQueries::class => DatabaseInventoryQueries::class,
    ];

    public function boot(): void
    {
        Event::listen(StockChanged::class, [WatchLowStock::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileInventoryCommand::class, ExpireReservationsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('inventory:expire-reservations')->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->command('inventory:reconcile')->dailyAt('04:10')->withoutOverlapping()->onOneServer()
                ->onFailure(fn () => Log::critical('Nightly inventory reconciliation failed'));
        });
    }
}
