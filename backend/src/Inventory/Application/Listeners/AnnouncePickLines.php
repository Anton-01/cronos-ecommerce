<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application\Listeners;

use Cronos\Checkout\Domain\Events\CheckoutPaid;
use Cronos\Inventory\Application\AlertRaiser;
use Cronos\Inventory\Domain\Ports\InventoryQueries;
use Cronos\Inventory\Domain\ValueObjects\AlertProcess;

/** An order was paid: tell the people who pick that something has to leave a shelf. */
final class AnnouncePickLines
{
    public function __construct(private readonly InventoryQueries $queries, private readonly AlertRaiser $alerts) {}

    public function handle(CheckoutPaid $e): void
    {
        $lines = $this->queries->soldForOrder($e->checkoutId);
        if ($lines === []) {
            return;
        }
        $this->alerts->raise(AlertProcess::PICK_PENDING, 'Pedido por surtir', count($lines).' línea(s) por sacar del anaquel.', 'pick:'.$e->checkoutId, warehouseId: $lines[0]['warehouse_id'], data: ['order' => $e->checkoutId, 'lines' => count($lines)]);
    }
}
