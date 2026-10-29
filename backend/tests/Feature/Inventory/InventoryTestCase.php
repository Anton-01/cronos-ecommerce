<?php

namespace Tests\Feature\Inventory;

use Cronos\Inventory\Application\Actor;
use Cronos\Inventory\Application\StockLedger;
use Cronos\Inventory\Domain\Ports\WarehouseRepository;
use Cronos\Inventory\Domain\ValueObjects\MovementType;
use Database\Seeders\WarehousesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Pricing\PricingTestCase;

abstract class InventoryTestCase extends PricingTestCase
{
    protected int $mx;

    protected int $us;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WarehousesSeeder::class);
        $all = collect(app(WarehouseRepository::class)->all())->keyBy('code');
        $this->mx = $all['MX-01']['id'];
        $this->us = $all['US-01']['id'];
    }

    protected function actor(): Actor
    {
        return Actor::system();
    }

    protected function stock(string $productId, int $amount, ?int $warehouse = null): string
    {
        return app(StockLedger::class)->record($productId, $warehouse ?? $this->mx, MovementType::PURCHASE, $amount, $this->actor(), unitCost: '5.00');
    }

    /** @return array{on_hand: string, reserved: string, available: string} */
    protected function level(string $productId, ?int $warehouse = null): array
    {
        $r = DB::table('stock_levels')->where(['product_id' => $productId, 'warehouse_id' => $warehouse ?? $this->mx])->first();

        return ['on_hand' => (string) $r->on_hand, 'reserved' => (string) $r->reserved, 'available' => (string) $r->available];
    }

    protected function productStock(string $productId): string
    {
        return (string) DB::table('products')->where('id', $productId)->value('stock_quantity');
    }
}
