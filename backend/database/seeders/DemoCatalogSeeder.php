<?php

declare(strict_types=1);

namespace Database\Seeders;

use Cronos\Catalog\Application\AttributeManager;
use Cronos\Catalog\Application\CategoryManager;
use Cronos\Catalog\Application\ProductManager;
use Cronos\Catalog\Application\VariationManager;
use Cronos\Inventory\Application\Actor;
use Cronos\Inventory\Application\StockLedger;
use Cronos\Inventory\Domain\Ports\WarehouseRepository;
use Cronos\Inventory\Domain\ValueObjects\MovementType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Development-only sample catalogue (ribbons by the metre and the roll, pieces, a variable ribbon) so the storefront can be seen with content.
 * Not part of DatabaseSeeder on purpose: `php artisan db:seed --class=DemoCatalogSeeder`. Pictures are not seeded; the storefront shows neutral placeholders.
 */
final class DemoCatalogSeeder extends Seeder
{
    public function run(ProductManager $products, CategoryManager $categories, AttributeManager $attributes, StockLedger $ledger, WarehouseRepository $warehouses, VariationManager $variations): void
    {
        $tr = static fn (string $es, string $en, ?string $short = null, ?string $long = null): array => [
            'es-MX' => ['name' => $es, 'short_description' => $short, 'description' => $long],
            'en-US' => ['name' => $en, 'short_description' => $short, 'description' => $long],
        ];
        $cat = static fn (string $es, string $en, ?string $parent = null): string => $categories->create(['parent_id' => $parent, 'translations' => ['es-MX' => ['name' => $es], 'en-US' => ['name' => $en]]]);

        $ribbons = $cat('Listones', 'Ribbons');
        $satin = $cat('Satén', 'Satin', $ribbons);
        $cat('Organza', 'Organza', $ribbons);
        $bows = $cat('Moños', 'Bows');
        $cat('Accesorios', 'Accessories');

        $color = DB::table('attributes')->where('code', 'color')->whereNull('deleted_at')->value('id') ?? $attributes->create('color', 'color', true, 0, ['es-MX' => 'Color', 'en-US' => 'Color']);
        // The client's colour list may already exist (ClientCatalogSeeder): reuse its values.
        $colorValue = fn (string $code, array $meta, int $pos, array $labels) => DB::table('attribute_values')->where(['attribute_id' => $color, 'code' => $code])->value('id') ?? $attributes->addValue($color, $code, $meta, $pos, $labels);
        $red = $colorValue('rojo', ['hex' => '#C8285A'], 0, ['es-MX' => 'Rojo', 'en-US' => 'Red']);
        $blue = $colorValue('azul', ['hex' => '#1458C8'], 1, ['es-MX' => 'Azul', 'en-US' => 'Blue']);
        $gold = $colorValue('dorado', ['hex' => '#D4A93C'], 2, ['es-MX' => 'Dorado', 'en-US' => 'Gold']);

        $ids = [];
        $ribbon = fn (string $es, string $en, string $sku, string $price, string $rollPrice, string $parent) => $products->create([
            'type' => 'simple', 'sku' => $sku, 'price' => $price, 'category_ids' => [$parent], 'primary_category_id' => $parent,
            'sales' => ['unit' => 'meter', 'step' => '0.5', 'min' => 1, 'max' => 50, 'sell_by_cut' => true, 'sell_by_roll' => true, 'roll_length' => 25, 'roll_price' => $rollPrice],
            'translations' => $tr($es, $en, 'Ideal para moños y envoltura.', "Listón de alta calidad vendido por metro o por rollo completo.\nCorte en múltiplos de 0.5 m."),
        ]);
        foreach ([['Listón de satén rojo', 'Red satin ribbon', 'SAT-ROJO', '18.00', '400.00'], ['Listón de satén azul', 'Blue satin ribbon', 'SAT-AZUL', '18.00', '400.00'], ['Listón de organza dorado', 'Gold organza ribbon', 'ORG-ORO', '24.00', '520.00']] as [$es, $en, $sku, $p, $roll]) {
            $ids[] = $ribbon($es, $en, $sku, $p, $roll, $satin);
        }
        foreach ([['Moño decorativo clásico', 'Classic decorative bow', 'MONO-1', '25.00'], ['Moño navideño', 'Christmas bow', 'MONO-2', '32.00'], ['Moño de regalo', 'Gift bow', 'MONO-3', '19.00']] as [$es, $en, $sku, $p]) {
            $ids[] = $products->create(['type' => 'simple', 'sku' => $sku, 'price' => $p, 'category_ids' => [$bows], 'primary_category_id' => $bows, 'translations' => $tr($es, $en, 'Se vende por pieza.')]);
        }

        $variable = $products->create([
            'type' => 'variable', 'sku' => 'LISO', 'price' => '10.00', 'category_ids' => [$ribbons], 'primary_category_id' => $ribbons,
            'sales' => ['unit' => 'meter', 'step' => '0.5', 'min' => 1, 'sell_by_cut' => true, 'sell_by_roll' => true, 'roll_length' => 25],
            'translations' => $tr('Listón liso', 'Plain ribbon', 'Disponible en varios colores.'),
        ]);
        $products->setAttributes($variable, [['attribute_id' => $color, 'is_variation' => true]]);
        $products->setOfferedValues($variable, [$red, $blue, $gold]);
        $generated = $variations->generate($variable);
        foreach ($generated as $variation) {
            $products->publish($variation);
        }
        $ids = [...$ids, ...$generated];
        $ids[] = $variable;

        $mx = collect($warehouses->all())->firstWhere('code', 'MX-01')['id'];
        $us = collect($warehouses->all())->firstWhere('code', 'US-01')['id'] ?? $mx;
        foreach ($ids as $id) {
            $products->publish($id);
            $ledger->record($id, $mx, MovementType::PURCHASE, 200, Actor::system(), unitCost: '5.00');
            $ledger->record($id, $us, MovementType::PURCHASE, 200, Actor::system(), unitCost: '5.00');
        }
    }
}
