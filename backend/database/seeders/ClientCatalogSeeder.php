<?php

namespace Database\Seeders;

use Cronos\Catalog\Application\AttributeManager;
use Cronos\Catalog\Application\CatalogOptionManager;
use Cronos\Catalog\Application\ProductTypeManager;
use Cronos\Catalog\Domain\Ports\AttributeRepository;
use Cronos\Catalog\Domain\Ports\CatalogOptionRepository;
use Cronos\Catalog\Domain\Ports\ProductTypeRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The lists the client works with when registering a product (packaging, units, colours, materials, finishes, widths) and the first
 * product types. Idempotent: whatever exists is left exactly as the client edited it. Every list can be changed from the admin.
 */
class ClientCatalogSeeder extends Seeder
{
    /** @var array<string, list<array{0: string, 1: string, 2: string}>> code, es, en */
    private const MATERIALS = [
        ['satin', 'Satín', 'Satin'], ['grosgrain', 'Grosgrain', 'Grosgrain'], ['organza', 'Organza', 'Organza'], ['terciopelo', 'Terciopelo', 'Velvet'], ['yute', 'Yute', 'Jute'],
        ['algodon', 'Algodón', 'Cotton'], ['poliester', 'Poliéster', 'Polyester'], ['glitter', 'Glitter', 'Glitter'], ['foami', 'Foami', 'Foam'], ['acrilico', 'Acrílico', 'Acrylic'],
        ['resina', 'Resina', 'Resin'], ['metal', 'Metal', 'Metal'], ['pvc', 'PVC', 'PVC'], ['papel', 'Papel', 'Paper'], ['vinil', 'Vinil', 'Vinyl'],
    ];

    private const FINISHES = [
        ['mate', 'Mate', 'Matte'], ['brillante', 'Brillante', 'Glossy'], ['glitter', 'Glitter', 'Glitter'], ['lentejuela', 'Lentejuela', 'Sequin'], ['metalico', 'Metálico', 'Metallic'],
        ['holografico', 'Holográfico', 'Holographic'], ['satinado', 'Satinado', 'Satin'], ['otro', 'Otro', 'Other'],
    ];

    /** code, es, en, hex, pattern */
    private const COLORS = [
        ['blanco', 'Blanco', 'White', '#FFFFFF'], ['negro', 'Negro', 'Black', '#111111'], ['rojo', 'Rojo', 'Red', '#C8285A'], ['rosa', 'Rosa', 'Pink', '#F4A6C0'], ['fucsia', 'Fucsia', 'Fuchsia', '#D6177F'],
        ['morado', 'Morado', 'Purple', '#6B2FA0'], ['lila', 'Lila', 'Lilac', '#C8A2E0'], ['azul', 'Azul', 'Blue', '#1458C8'], ['azul_marino', 'Azul marino', 'Navy', '#14275A'], ['azul_cielo', 'Azul cielo', 'Sky blue', '#87CEEB'],
        ['turquesa', 'Turquesa', 'Turquoise', '#20B2AA'], ['verde', 'Verde', 'Green', '#2E8B57'], ['verde_menta', 'Verde menta', 'Mint', '#98E3C0'], ['amarillo', 'Amarillo', 'Yellow', '#F4D03F'], ['naranja', 'Naranja', 'Orange', '#F28C28'],
        ['cafe', 'Café', 'Brown', '#7B4B2A'], ['beige', 'Beige', 'Beige', '#E6D3B3'], ['dorado', 'Dorado', 'Gold', '#D4A93C'], ['plateado', 'Plateado', 'Silver', '#B8BEC6'],
        ['multicolor', 'Multicolor', 'Multicolour', '#8B5CF6', 'multicolor'], ['arcoiris', 'Arcoíris', 'Rainbow', '#F59E0B', 'rainbow'], ['transparente', 'Transparente', 'Clear', '#E5E7EB', 'transparent'],
    ];

    private const WIDTHS_MM = [3, 6, 10, 16, 22, 25, 38, 50, 75, 100];

    public function run(CatalogOptionRepository $options, CatalogOptionManager $lists, AttributeRepository $attributes, AttributeManager $attrs, ProductTypeRepository $types, ProductTypeManager $typeManager): void
    {
        $has = fn (string $catalog, string $code) => $options->findByCode($catalog, $code) !== null;
        $add = function (string $catalog, string $code, string $es, string $en, ?array $meta, int $pos) use ($has, $lists): void {
            $has($catalog, $code) || $lists->create($catalog, $code, ['es-MX' => $es, 'en-US' => $en], $meta, $pos);
        };

        foreach ([['unitario', 'Unitario', 'Single'], ['en_conjunto', 'En conjunto', 'Set'], ['por_caja', 'Por caja', 'Boxed']] as $i => [$c, $es, $en]) {
            $add('packaging_type', $c, $es, $en, null, $i);
        }
        // Base unit: what stock is counted in. Presentation: how it comes packed. `sell_unit` is how the shop sells it (metres are cut, the rest are counted).
        $units = [
            ['pieza', 'pieza', 'piece', 'piezas', 'pieces', true, false, 'piece', false], ['metro', 'metro', 'meter', 'metros', 'meters', true, true, 'meter', false],
            ['rollo', 'rollo', 'roll', 'rollos', 'rolls', true, true, 'piece', true], ['paquete', 'paquete', 'pack', 'paquetes', 'packs', true, true, 'piece', false],
            ['docena', 'docena', 'dozen', 'docenas', 'dozens', true, true, 'piece', false],
        ];
        foreach ($units as $i => [$c, $es, $en, $pes, $pen, $base, $pres, $sell, $roll]) {
            $add('unit', $c, $es, $en, ['sell_unit' => $sell, 'base' => $base, 'presentation' => $pres, 'roll' => $roll, 'plural' => ['es-MX' => $pes, 'en-US' => $pen]], $i);
        }
        foreach ([['g', 'g', 'g', 1], ['kg', 'kg', 'kg', 1000]] as $i => [$c, $es, $en, $f]) {
            $add('weight_unit', $c, $es, $en, ['factor' => $f], $i);
        }
        foreach ([['mm', 'mm', 'mm', 1], ['cm', 'cm', 'cm', 10], ['m', 'm', 'm', 1000]] as $i => [$c, $es, $en, $f]) {
            $add('length_unit', $c, $es, $en, ['factor' => $f], $i);
        }

        $attribute = function (string $code, string $type, string $es, string $en, int $pos) use ($attrs): string {
            $existing = DB::table('attributes')->where('code', $code)->whereNull('deleted_at')->value('id');

            return $existing ?? $attrs->create($code, $type, true, $pos, ['es-MX' => $es, 'en-US' => $en]);
        };
        $value = function (string $attributeId, string $code, string $es, string $en, ?array $meta, int $pos) use ($attributes, $attrs): void {
            $attributes->valueCodeTaken($attributeId, $code) || $attrs->addValue($attributeId, $code, $meta, $pos, ['es-MX' => $es, 'en-US' => $en]);
        };

        $color = $attribute('color', 'color', 'Color', 'Colour', 0);
        foreach (self::COLORS as $i => $c) {
            $value($color, $c[0], $c[1], $c[2], ['hex' => $c[3]] + (isset($c[4]) ? ['pattern' => $c[4]] : []), $i);
        }
        $material = $attribute('material', 'select', 'Material', 'Material', 1);
        foreach (self::MATERIALS as $i => [$c, $es, $en]) {
            $value($material, $c, $es, $en, null, $i);
        }
        $finish = $attribute('finish', 'select', 'Acabado', 'Finish', 2);
        foreach (self::FINISHES as $i => [$c, $es, $en]) {
            $value($finish, $c, $es, $en, null, $i);
        }
        $width = $attribute('width', 'select', 'Ancho', 'Width', 3);
        foreach (self::WIDTHS_MM as $i => $mm) {
            $value($width, "{$mm}_mm", "{$mm} mm", "{$mm} mm", null, $i);
        }

        $make = function (string $code, array $input) use ($types, $typeManager): void {
            $types->codeTaken($code) || $typeManager->create(['code' => $code] + $input);
        };
        $make('liston', [
            'names' => ['es-MX' => 'Listón', 'en-US' => 'Ribbon'], 'sku_prefix' => 'LIS', 'position' => 0,
            'sales' => ['unit' => 'meter', 'step' => '0.5', 'min' => '1', 'sell_by_cut' => true, 'sell_by_roll' => true, 'roll_length' => '25'],
            'required_fields' => ['design', 'packaging_type', 'base_unit'], 'variant_attribute_ids' => [$color, $width, $finish], 'product_attribute_ids' => [$material],
        ]);
        $make('pieza_decorativa', [
            'names' => ['es-MX' => 'Pieza decorativa', 'en-US' => 'Decorative piece'], 'sku_prefix' => 'PZA', 'position' => 1,
            'sales' => ['unit' => 'piece'], 'required_fields' => ['design', 'packaging_type', 'base_unit'], 'variant_attribute_ids' => [$color], 'product_attribute_ids' => [$material, $finish],
        ]);
    }
}
