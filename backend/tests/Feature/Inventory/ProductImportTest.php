<?php

namespace Tests\Feature\Inventory;

use Cronos\Catalog\Application\ProductImporter;
use Cronos\Catalog\Application\ProductSpecs;
use Cronos\Catalog\Domain\Exceptions\InvalidProduct;
use Cronos\Inventory\Application\LocationManager;
use Cronos\Inventory\Application\WarehouseOperations;
use Database\Seeders\ClientCatalogSeeder;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

final class ProductImportTest extends InventoryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClientCatalogSeeder::class);
        app(LocationManager::class)->create($this->mx, ['code' => 'E03', 'name' => 'Estante 03']);
    }

    private const HEAD = ['Grupo', 'Tipo de producto', 'Nombre', 'Precio', 'Diseño o modelo', 'Tipo de empaque', 'Unidad base', 'Presentación', 'Contenido por presentación', 'Peso', 'Unidad de peso', 'Material', 'Color principal', 'Ancho', 'Acabado', 'Código de proveedor', 'Ubicación (código)', 'Cantidad inicial'];

    /** @param list<list<string>> $rows */
    private function csv(array $rows, array $head = self::HEAD): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        $fh = fopen($path, 'w');
        foreach ([$head, ...$rows] as $r) {
            fputcsv($fh, $r, ',', '"', '\\');
        }
        fclose($fh);

        return $path;
    }

    /** @return list<string> a row in the order of HEAD with the given overrides by header */
    private function row(array $over): array
    {
        $base = ['G1', 'Listón', 'Listón de satén', '18.00', 'Liso', 'Unitario', 'metros', 'rollo', '25', '120', 'g', 'Satín', 'Rojo', '25 mm', 'Brillante', 'PROV-1', 'E03', '40'];

        return array_values(array_replace(array_combine(self::HEAD, $base), $over));
    }

    private function importer(): ProductImporter
    {
        return app(ProductImporter::class);
    }

    public function test_a_dry_run_reports_what_would_happen_and_saves_nothing(): void
    {
        $path = $this->csv([$this->row([]), $this->row(['Color principal' => 'Azul', 'Código de proveedor' => 'PROV-2']), $this->row(['Ancho' => '10 mm'])]);
        $before = DB::table('products')->count();

        $r = $this->importer()->run($path, 'csv', false);

        $this->assertSame([false, 3, 1, 3, []], [$r['applied'], $r['rows'], $r['products'], $r['variants'], $r['errors']]);
        $this->assertSame($before, DB::table('products')->count());
        $this->assertSame(0, DB::table('sku_counters')->count(), 'not even the SKU counter moved');
    }

    public function test_applying_creates_the_product_with_only_the_listed_combinations_and_its_stock(): void
    {
        $path = $this->csv([
            $this->row([]), $this->row(['Color principal' => 'Azul', 'Código de proveedor' => 'PROV-2', 'Cantidad inicial' => '10']), $this->row(['Ancho' => '10 mm', 'Cantidad inicial' => '5']),
            $this->row(['Grupo' => 'G2', 'Tipo de producto' => 'Pieza decorativa', 'Nombre' => 'Moño rojo', 'Precio' => '25', 'Unidad base' => 'pieza', 'Presentación' => '', 'Contenido por presentación' => '', 'Peso' => '', 'Material' => 'Satín', 'Color principal' => 'Rojo', 'Ancho' => '', 'Acabado' => '', 'Código de proveedor' => '', 'Ubicación (código)' => '', 'Cantidad inicial' => '']),
        ]);

        $r = $this->importer()->run($path, 'csv', true, null, function (array $product, array $lines): void {
            foreach ($lines as $l) {
                app(WarehouseOperations::class)->receive($l['product_id'], $this->mx, $l['quantity'], $this->actor(), DB::table('warehouse_locations')->where('code', 'E03')->value('id'), null, 'imp-'.$l['row']);
            }
        });

        $this->assertSame([true, 2, 4, []], [$r['applied'], $r['products'], $r['variants'], $r['errors']]);
        $ribbon = collect($r['created'])->firstWhere('group', 'G1');
        $this->assertSame(['LIS-00001', 'LIS-00002', 'LIS-00003'], $ribbon['skus'], 'red 25, blue 25, red 10: not the 4 pairings');
        $this->assertSame(['PZA-00001'], collect($r['created'])->firstWhere('group', 'G2')['skus']);

        $variationSkus = DB::table('products')->where('parent_id', $ribbon['product_id'])->orderBy('sku')->pluck('sku')->all();
        $this->assertSame($ribbon['skus'], $variationSkus);
        $this->assertSame('55.000', (string) DB::table('location_stock')->sum('quantity'), '40 + 10 + 5 received into the shelf');
        $this->assertSame('120', app(ProductSpecs::class)->get($ribbon['product_id'])['specs']['weight']['value']);
        $this->assertSame('PROV-2', app(ProductSpecs::class)->get(DB::table('products')->where('sku', 'LIS-00002')->value('id'))['specs']['supplier_code']);
        $this->assertSame('meter', DB::table('products')->where('id', $ribbon['product_id'])->value('sell_unit'));
    }

    public function test_every_problem_is_reported_in_one_pass_and_then_nothing_is_saved(): void
    {
        $path = $this->csv([
            $this->row(['Grupo' => 'OK']),
            $this->row(['Grupo' => 'BAD1', 'Color principal' => 'Verde pistache']),
            $this->row(['Grupo' => 'BAD2', 'Tipo de producto' => 'Cosa rara']),
            $this->row(['Grupo' => 'BAD3', 'Tipo de empaque' => 'En globo']),
            $this->row(['Grupo' => 'BAD4', 'Ubicación (código)' => 'NOEXISTE']),
        ]);
        $before = DB::table('products')->count();
        $r = $this->importer()->run($path, 'csv', true, null, function (array $p, array $lines): void {
            foreach ($lines as $l) {
                if ($l['location'] === 'NOEXISTE') {
                    throw new InvalidProduct("row {$l['row']}: the location [{$l['location']}] does not exist");
                }
            }
        });

        $this->assertFalse($r['applied']);
        $this->assertCount(4, $r['errors']);
        $text = implode(' | ', array_column($r['errors'], 'message'));
        $this->assertStringContainsString('Verde pistache', $text);
        $this->assertStringContainsString('Valid:', $text, 'the error tells which values are valid');
        $this->assertStringContainsString('Cosa rara', $text);
        $this->assertStringContainsString('En globo', $text);
        $this->assertSame($before, DB::table('products')->count(), 'the good product was not saved either');
    }

    public function test_the_rows_of_one_product_must_agree_on_what_belongs_to_the_product(): void
    {
        $path = $this->csv([$this->row([]), $this->row(['Color principal' => 'Azul', 'Diseño o modelo' => 'Estampado'])]);
        $r = $this->importer()->run($path, 'csv', true);

        $this->assertStringContainsString('row 3', $r['errors'][0]['message']);
        $this->assertStringContainsString('Diseño o modelo', $r['errors'][0]['message']);
    }

    public function test_pending_and_not_applicable_are_written_in_the_cells(): void
    {
        $path = $this->csv([$this->row(['Diseño o modelo' => 'n/a', 'Peso' => 'pendiente', 'Unidad de peso' => '', 'Ubicación (código)' => '', 'Cantidad inicial' => ''])]);
        $r = $this->importer()->run($path, 'csv', true);

        $this->assertSame([], $r['errors']);
        $sheet = app(ProductSpecs::class)->get($r['created'][0]['product_id']);
        $this->assertSame(['not_applicable', 'pending', 'pending'], [$sheet['states']['design'], $sheet['states']['weight'], $sheet['states']['height']]);
        $this->assertSame([], $sheet['missing'], 'design is "not applicable": an answer');
    }

    public function test_an_excel_file_reads_the_same_as_a_csv_and_accepts_headers_without_accents(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $w = new Writer;
        $w->openToFile($path);
        $w->addRow(Row::fromValues(['grupo', 'tipo', 'nombre', 'precio', 'diseno', 'empaque', 'unidad base', 'color', 'ancho']));
        $w->addRow(Row::fromValues(['G', 'liston', 'Listón xlsx', 18, 'Liso', 'unitario', 'Metros', 'Rojo', '25 mm']));
        $w->close();

        $r = $this->importer()->run($path, 'xlsx', true);

        $this->assertSame([true, 1, 1, []], [$r['applied'], $r['products'], $r['variants'], $r['errors']]);
        $this->assertSame('18.00', (string) DB::table('products')->where('id', DB::table('products')->where('parent_id', $r['created'][0]['product_id'])->value('parent_id'))->value('price'));
    }

    public function test_the_template_lists_every_column_the_importer_understands(): void
    {
        $t = $this->importer()->template();
        $this->assertCount(count($t['headers']), $t['example']);
        $path = $this->csv([$t['example']], $t['headers']);
        $this->assertSame([], $this->importer()->run($path, 'csv', false)['errors'] === [] ? [] : ['the example row of the template must be valid'], 'the example is a valid row');
    }

    public function test_a_file_without_headers_is_explained(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->importer()->read($this->csv([['x', 'y']], ['a', 'b']), 'csv');
    }
}
