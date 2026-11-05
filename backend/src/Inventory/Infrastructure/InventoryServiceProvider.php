<?php

declare(strict_types=1);

namespace Cronos\Inventory\Infrastructure;

use Cronos\Inventory\Application\Listeners\WatchLowStock;
use Cronos\Inventory\Domain\Events\StockChanged;
use Cronos\Inventory\Domain\Ports\InventoryQueries;
use Cronos\Inventory\Domain\Ports\LocationRepository;
use Cronos\Inventory\Domain\Ports\LocationStore;
use Cronos\Inventory\Domain\Ports\OperationsStore;
use Cronos\Inventory\Domain\Ports\ProductStockInfo;
use Cronos\Inventory\Domain\Ports\ReservationStore;
use Cronos\Inventory\Domain\Ports\StockStore;
use Cronos\Inventory\Domain\Ports\WarehouseRepository;
use Cronos\Inventory\Infrastructure\Console\AssignOpeningStockCommand;
use Cronos\Inventory\Infrastructure\Console\ExpireReservationsCommand;
use Cronos\Inventory\Infrastructure\Console\IssueStockCodesCommand;
use Cronos\Inventory\Infrastructure\Console\ReconcileInventoryCommand;
use Cronos\Inventory\Infrastructure\Console\ReconcileLocationsCommand;
use Cronos\Inventory\Infrastructure\Persistence\DatabaseInventoryQueries;
use Cronos\Inventory\Infrastructure\Persistence\DatabaseLocationRepository;
use Cronos\Inventory\Infrastructure\Persistence\DatabaseLocationStore;
use Cronos\Inventory\Infrastructure\Persistence\DatabaseOperationsStore;
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
        LocationRepository::class => DatabaseLocationRepository::class,
        LocationStore::class => DatabaseLocationStore::class,
        OperationsStore::class => DatabaseOperationsStore::class,
    ];

    public function boot(): void
    {
        Event::listen(StockChanged::class, [WatchLowStock::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileInventoryCommand::class, ExpireReservationsCommand::class, AssignOpeningStockCommand::class, ReconcileLocationsCommand::class, IssueStockCodesCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('inventory:expire-reservations')->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->command('inventory:reconcile-locations')->dailyAt('04:20')->withoutOverlapping()->onOneServer()
                ->onFailure(fn () => Log::critical('Nightly location reconciliation failed'));
            $schedule->command('inventory:reconcile')->dailyAt('04:10')->withoutOverlapping()->onOneServer()
                ->onFailure(fn () => Log::critical('Nightly inventory reconciliation failed'));
        });
    }
}
