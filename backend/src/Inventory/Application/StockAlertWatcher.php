<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Brick\Math\BigDecimal;
use Cronos\Inventory\Domain\Events\LocationStockChanged;
use Cronos\Inventory\Domain\Events\StockChanged;
use Cronos\Inventory\Domain\Ports\AlertStore;
use Cronos\Inventory\Domain\ValueObjects\AlertProcess;
use Cronos\Inventory\Domain\ValueObjects\StockState;

/**
 * After stock moves, compares each place with its thresholds and speaks only when the STATE changes (ok → low → critical → out and back).
 * The state row is locked while it is evaluated, so two movements at once cannot both announce the same change.
 */
final class StockAlertWatcher
{
    private const PROCESS_OF = [StockState::LOW => AlertProcess::STOCK_LOW, StockState::CRITICAL => AlertProcess::STOCK_CRITICAL, StockState::OUT => AlertProcess::STOCK_OUT, StockState::OK => AlertProcess::STOCK_RECOVERED];

    private const WORDS = [StockState::LOW => 'bajas', StockState::CRITICAL => 'críticas', StockState::OUT => 'agotadas', StockState::OK => 'recuperadas'];

    public function __construct(private readonly AlertStore $store, private readonly AlertRaiser $alerts, private readonly WarehousePolicies $policies) {}

    /** The books of a product changed: check every warehouse that holds it. */
    public function onStockChanged(StockChanged $e): void
    {
        foreach ($this->store->warehousesOf($e->productId) as $warehouseId) {
            $this->evaluate($e->productId, $warehouseId, null);
        }
    }

    public function onLocationChanged(LocationStockChanged $e): void
    {
        $this->evaluate($e->productId, $e->warehouseId, $e->locationId);
    }

    public function evaluate(string $productId, int $warehouseId, ?string $locationId): void
    {
        $announce = $this->store->atomically(function () use ($productId, $warehouseId, $locationId): ?array {
            $threshold = $this->store->threshold($productId, $warehouseId, $locationId);
            if ($threshold === null) {
                return null;
            }
            $previous = $this->store->lockState($productId, $warehouseId, $locationId)['state'];
            $quantity = $this->store->quantityAt($productId, $warehouseId, $locationId);
            $next = StockState::next($previous, BigDecimal::of($quantity), BigDecimal::of($threshold['minimum']), $threshold['critical'] === null ? null : BigDecimal::of($threshold['critical']), $this->policies->current()['recovery_margin_percent']);
            if ($next === $previous) {
                return null;
            }
            $this->store->saveState($productId, $warehouseId, $locationId, $next, $quantity);

            return ['from' => $previous, 'to' => $next, 'quantity' => $quantity, 'minimum' => $threshold['minimum']];
        });

        $announce === null || $this->announce($productId, $warehouseId, $locationId, $announce);
    }

    /** @param array{from: string, to: string, quantity: string, minimum: string} $change */
    private function announce(string $productId, int $warehouseId, ?string $locationId, array $change): void
    {
        $product = $this->store->describeProduct($productId);
        $place = $locationId === null ? ($this->store->warehouseName($warehouseId) ?? "almacén {$warehouseId}") : (($l = $this->store->describeLocation($locationId)) === null ? $locationId : "{$l['code']} ({$l['name']})");
        $label = ($product['sku'] ?? $productId).($product['name'] ? ' — '.$product['name'] : '');
        $qty = rtrim(rtrim($change['quantity'], '0'), '.') ?: '0';
        $stamp = (string) hrtime(true); // each change is its own event; the state lock already prevents double announcements

        $this->alerts->raise(
            self::PROCESS_OF[$change['to']],
            'Existencias '.self::WORDS[$change['to']].": {$label}",
            "{$label} en {$place}: quedan {$qty} (mínimo {$change['minimum']}). Antes estaba en «{$change['from']}».",
            "stock:{$productId}:{$warehouseId}:".($locationId ?? 'W').":{$change['to']}:{$stamp}",
            $productId, $warehouseId, $locationId, ['from' => $change['from'], 'to' => $change['to'], 'quantity' => $change['quantity'], 'minimum' => $change['minimum']],
        );
    }
}
