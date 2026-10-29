<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application\Listeners;

use Cronos\Inventory\Domain\Events\StockChanged;
use Cronos\Inventory\Domain\Events\StockLow;
use Cronos\Inventory\Domain\Ports\InventoryQueries;
use Cronos\Shared\Domain\DomainEventDispatcher;
use Illuminate\Support\Facades\Log;

/** After stock moves, tells the rest of the system when a product is at or below its low-stock threshold. */
final class WatchLowStock
{
    public function __construct(private readonly InventoryQueries $queries, private readonly DomainEventDispatcher $events) {}

    public function handle(StockChanged $event): void
    {
        $row = $this->queries->lowStock($event->productId)[0] ?? null;
        if ($row === null) {
            return;
        }

        Log::warning('Low stock', ['product' => $row['sku'], 'available' => $row['available'], 'threshold' => $row['low_stock_threshold']]);
        $this->events->dispatch(new StockLow($event->productId, $row['sku'], (string) $row['available'], (string) $row['low_stock_threshold']));
    }
}
