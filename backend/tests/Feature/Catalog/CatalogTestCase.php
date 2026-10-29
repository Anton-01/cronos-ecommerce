<?php

namespace Tests\Feature\Catalog;

use Cronos\Catalog\Application\AttributeManager;
use Cronos\Catalog\Application\CategoryManager;
use Cronos\Catalog\Application\ProductManager;
use Cronos\Catalog\Application\TagManager;
use Cronos\Catalog\Application\VariationManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Builds catalogue data through the same managers the API uses. */
abstract class CatalogTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
    }

    protected function categories(): CategoryManager
    {
        return app(CategoryManager::class);
    }

    protected function tags(): TagManager
    {
        return app(TagManager::class);
    }

    protected function attributes(): AttributeManager
    {
        return app(AttributeManager::class);
    }

    protected function products(): ProductManager
    {
        return app(ProductManager::class);
    }

    protected function variations(): VariationManager
    {
        return app(VariationManager::class);
    }

    protected function category(string $name, ?string $parent = null, array $extra = []): string
    {
        return $this->categories()->create([...$extra, 'parent_id' => $parent, 'translations' => ['es-MX' => ['name' => $name], 'en-US' => ['name' => $extra['en'] ?? $name.' (en)']]]);
    }

    /** A ribbon sold both by free cut (0.5 m steps) and by 25 m rolls, with a roll discount. */
    protected function ribbon(string $name = 'Listón de satén', string $sku = 'LIST-SATEN', string $price = '18.00', array $extra = []): string
    {
        return $this->products()->create([
            'type' => 'simple', 'sku' => $sku, 'price' => $price,
            'sales' => ['unit' => 'meter', 'step' => '0.5', 'min' => 1, 'max' => 50, 'sell_by_cut' => true, 'sell_by_roll' => true, 'roll_length' => 25, 'roll_price' => '400.00'],
            'translations' => ['es-MX' => ['name' => $name, 'short_description' => 'Ideal para moños'], 'en-US' => ['name' => $name.' (en)']],
            ...$extra,
        ]);
    }

    protected function piece(string $name = 'Moño decorativo', string $sku = 'MONO-1', string $price = '25.00', array $extra = []): string
    {
        return $this->products()->create(['type' => 'simple', 'sku' => $sku, 'price' => $price, 'translations' => ['es-MX' => ['name' => $name]], ...$extra]);
    }

    /** A published product, ready for the storefront. */
    protected function published(string $id): string
    {
        $this->products()->publish($id);

        return $id;
    }

    /** @return array{colorId: string, widthId: string, red: string, blue: string, w10: string, w25: string} */
    protected function colorAndWidth(): array
    {
        $color = $this->attributes()->create('color', 'color', true, 0, ['es-MX' => 'Color']);
        $width = $this->attributes()->create('width', 'select', true, 1, ['es-MX' => 'Ancho']);

        return [
            'colorId' => $color, 'widthId' => $width,
            'red' => $this->attributes()->addValue($color, 'rojo', ['hex' => '#C8285A'], 0, ['es-MX' => 'Rojo']),
            'blue' => $this->attributes()->addValue($color, 'azul', ['hex' => '#1458C8'], 1, ['es-MX' => 'Azul']),
            'w10' => $this->attributes()->addValue($width, '10mm', null, 0, ['es-MX' => '10 mm']),
            'w25' => $this->attributes()->addValue($width, '25mm', null, 1, ['es-MX' => '25 mm']),
        ];
    }

    /** A variable ribbon with color x width offered (nothing generated yet). */
    protected function variableRibbon(array $a, string $name = 'Listón liso', string $sku = 'LISO', ?string $price = '10.00'): string
    {
        $id = $this->products()->create([
            'type' => 'variable', 'sku' => $sku, 'price' => $price,
            'sales' => ['unit' => 'meter', 'step' => '0.5', 'min' => 1, 'sell_by_cut' => true, 'sell_by_roll' => true, 'roll_length' => 25],
            'translations' => ['es-MX' => ['name' => $name]],
        ]);
        $this->products()->setAttributes($id, [['attribute_id' => $a['colorId'], 'is_variation' => true], ['attribute_id' => $a['widthId'], 'is_variation' => true]]);
        $this->products()->setOfferedValues($id, [$a['red'], $a['blue'], $a['w10'], $a['w25']]);

        return $id;
    }
}
