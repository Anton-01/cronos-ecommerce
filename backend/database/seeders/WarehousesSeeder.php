<?php

namespace Database\Seeders;

use Cronos\Inventory\Application\WarehouseManager;
use Cronos\Inventory\Domain\Ports\WarehouseRepository;
use Illuminate\Database\Seeder;

/** One warehouse per market (the model supports many). Only when none exist yet. */
class WarehousesSeeder extends Seeder
{
    public function run(WarehouseRepository $warehouses, WarehouseManager $manager): void
    {
        if ($warehouses->all() !== []) {
            return;
        }

        $manager->create(['code' => 'MX-01', 'name' => 'Bodega México', 'market_code' => 'MX', 'is_default' => true]);
        $manager->create(['code' => 'US-01', 'name' => 'Warehouse United States', 'market_code' => 'US']);
    }
}
