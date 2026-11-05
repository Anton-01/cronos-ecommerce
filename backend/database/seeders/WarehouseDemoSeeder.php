<?php

namespace Database\Seeders;

use Cronos\Inventory\Application\LocationManager;
use Cronos\Inventory\Domain\Ports\LocationRepository;
use Cronos\Inventory\Domain\Ports\WarehouseRepository;
use Illuminate\Database\Seeder;

/** Development only: the shelf tree the client described (Bodega › Pasillo › Estante › Caja, and Tienda › Mostrador / Exhibidor). Safe to run twice. */
class WarehouseDemoSeeder extends Seeder
{
    /** @var list<array{0: string, 1: string, 2: string, 3: ?string}> code, name, type, parent code */
    private const TREE = [
        ['BOD', 'Bodega', 'zone', null],
        ['A', 'Pasillo A', 'aisle', 'BOD'],
        ['E03', 'Estante 03', 'shelf', 'A'],
        ['C01', 'Caja 01', 'bin', 'E03'],
        ['C02', 'Caja 02', 'bin', 'E03'],
        ['B', 'Pasillo B', 'aisle', 'BOD'],
        ['E01', 'Estante 01', 'shelf', 'B'],
        ['TDA', 'Tienda', 'zone', null],
        ['MOS', 'Mostrador', 'shelf', 'TDA'],
        ['EXH', 'Exhibidor', 'shelf', 'TDA'],
    ];

    public function run(): void
    {
        $warehouse = collect(app(WarehouseRepository::class)->all())->firstWhere('is_default', true);
        if ($warehouse === null) {
            return;
        }
        $repo = app(LocationRepository::class);
        $manager = app(LocationManager::class);
        $ids = [];
        foreach (self::TREE as [$code, $name, $type, $parent]) {
            $existing = $repo->findByCode($warehouse['id'], $code);
            $ids[$code] = $existing['id'] ?? $manager->create($warehouse['id'], ['code' => $code, 'name' => $name, 'type' => $type, 'parent_id' => $parent === null ? null : $ids[$parent]]);
        }
    }
}
