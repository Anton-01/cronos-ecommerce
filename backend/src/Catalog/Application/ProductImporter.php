<?php

declare(strict_types=1);

namespace Cronos\Catalog\Application;

use Cronos\Catalog\Domain\Exceptions\InvalidProduct;
use Cronos\Catalog\Domain\Exceptions\InvalidVariation;
use Cronos\Catalog\Domain\Ports\AttributeRepository;
use Cronos\Catalog\Domain\Ports\CatalogOptionRepository;
use Cronos\Catalog\Domain\Ports\ProductRepository;
use Cronos\Catalog\Domain\Ports\ProductTypeRepository;
use Cronos\Catalog\Domain\Services\SpecFields;
use Cronos\Shared\Domain\DomainError;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Registers many products from a spreadsheet (one row per variant). It reads the file, groups rows into products, and runs each group
 * through the same quick registration as the form, so a sheet can never create what the form would refuse. A dry run does everything
 * for real inside a transaction and then undoes it: what it reports is exactly what the real run would do.
 */
final class ProductImporter
{
    /** @var array<string, array{header: string, aliases: list<string>, example: string}> */
    public const COLUMNS = [
        'group' => ['header' => 'Grupo', 'aliases' => ['grupo', 'group', 'producto'], 'example' => 'LISTON-ROJO'],
        'type' => ['header' => 'Tipo de producto', 'aliases' => ['tipo', 'tipo de producto', 'type'], 'example' => 'Listón'],
        'name' => ['header' => 'Nombre', 'aliases' => ['nombre', 'name'], 'example' => 'Listón de satén'],
        'name_en' => ['header' => 'Nombre (inglés)', 'aliases' => ['nombre ingles', 'nombre (ingles)', 'name en'], 'example' => 'Satin ribbon'],
        'price' => ['header' => 'Precio', 'aliases' => ['precio', 'price'], 'example' => '18.00'],
        'sku' => ['header' => 'SKU (vacío = automático)', 'aliases' => ['sku', 'sku (vacio = automatico)'], 'example' => ''],
        'design' => ['header' => 'Diseño o modelo', 'aliases' => ['diseno', 'diseno o modelo', 'modelo'], 'example' => 'Liso'],
        'packaging' => ['header' => 'Tipo de empaque', 'aliases' => ['empaque', 'tipo de empaque'], 'example' => 'Unitario'],
        'base_unit' => ['header' => 'Unidad base', 'aliases' => ['unidad base', 'unidad'], 'example' => 'metros'],
        'presentation' => ['header' => 'Presentación', 'aliases' => ['presentacion'], 'example' => 'rollo'],
        'per_presentation' => ['header' => 'Contenido por presentación', 'aliases' => ['contenido', 'contenido por presentacion'], 'example' => '25'],
        'weight' => ['header' => 'Peso', 'aliases' => ['peso'], 'example' => '120'],
        'weight_unit' => ['header' => 'Unidad de peso', 'aliases' => ['unidad de peso', 'unidad peso'], 'example' => 'g'],
        'length' => ['header' => 'Largo del producto', 'aliases' => ['largo', 'largo del producto'], 'example' => ''],
        'width_dim' => ['header' => 'Ancho del producto', 'aliases' => ['ancho del producto'], 'example' => ''],
        'height' => ['header' => 'Alto del producto', 'aliases' => ['alto', 'alto del producto'], 'example' => ''],
        'dim_unit' => ['header' => 'Unidad de medidas', 'aliases' => ['unidad de medidas', 'unidad medidas'], 'example' => 'cm'],
        'secondary_colors' => ['header' => 'Colores secundarios', 'aliases' => ['colores secundarios'], 'example' => 'Blanco, Dorado'],
        'uses' => ['header' => 'Usos o aplicaciones', 'aliases' => ['usos', 'usos o aplicaciones'], 'example' => 'moños, manualidades'],
        'notes' => ['header' => 'Observaciones del producto', 'aliases' => ['observaciones', 'observaciones del producto'], 'example' => ''],
        'legacy_code' => ['header' => 'Código anterior', 'aliases' => ['codigo anterior'], 'example' => ''],
        'material' => ['header' => 'Material', 'aliases' => ['material'], 'example' => 'Satín'],
        'color' => ['header' => 'Color principal', 'aliases' => ['color', 'color principal'], 'example' => 'Rojo'],
        'width' => ['header' => 'Ancho', 'aliases' => ['ancho'], 'example' => '25 mm'],
        'finish' => ['header' => 'Acabado', 'aliases' => ['acabado'], 'example' => 'Brillante'],
        'supplier_code' => ['header' => 'Código de proveedor', 'aliases' => ['codigo de proveedor', 'proveedor'], 'example' => ''],
        'variant_notes' => ['header' => 'Observaciones de la variante', 'aliases' => ['observaciones de la variante'], 'example' => ''],
        'location' => ['header' => 'Ubicación (código)', 'aliases' => ['ubicacion', 'ubicacion (codigo)'], 'example' => 'E03'],
        'quantity' => ['header' => 'Cantidad inicial', 'aliases' => ['cantidad', 'cantidad inicial'], 'example' => '100'],
    ];

    private const PENDING = ['pendiente', 'dato pendiente', 'pending'];

    private const NOT_APPLICABLE = ['n/a', 'na', 'no aplica', 'not applicable'];

    public function __construct(
        private readonly QuickProductCreator $creator,
        private readonly ProductTypeRepository $types,
        private readonly CatalogOptionRepository $options,
        private readonly ProductRepository $products,
        private readonly AttributeRepository $attributes,
    ) {}

    /** @return array{headers: list<string>, example: list<string>} */
    public function template(): array
    {
        return ['headers' => array_column(self::COLUMNS, 'header'), 'example' => array_column(self::COLUMNS, 'example')];
    }

    /**
     * @param  ?callable(array<string, mixed>, list<array<string, mixed>>): void  $onCreated  called inside the transaction for each product with its stock lines (location, quantity per variation)
     * @return array{applied: bool, rows: int, products: int, variants: int, errors: list<array{row: ?int, message: string}>, created: list<array{group: string, product_id: string, skus: list<string>}>}
     */
    public function run(string $path, string $extension, bool $apply, ?string $userId = null, ?callable $onCreated = null): array
    {
        $rows = $this->read($path, $extension);
        $report = ['applied' => false, 'rows' => count($rows), 'products' => 0, 'variants' => 0, 'errors' => [], 'created' => []];
        if ($rows === []) {
            $report['errors'][] = ['row' => null, 'message' => 'The file has no data rows.'];

            return $report;
        }
        count($rows) <= 2000 || throw new RuntimeException('A file can have at most 2000 rows; split it.');

        try {
            $this->products->atomically(function () use ($rows, $apply, $userId, $onCreated, &$report): void {
                foreach ($this->groups($rows) as $group => $groupRows) {
                    try {
                        // A savepoint per product: one bad product is reported and skipped, so the report lists every problem in one pass.
                        $result = $this->products->atomically(fn () => $this->createGroup((string) $group, $groupRows, $userId, $onCreated));
                        $report['products']++;
                        $report['variants'] += max(1, count($result['variations']));
                        $report['created'][] = ['group' => (string) $group, 'product_id' => $result['product_id'], 'skus' => $result['variations'] === [] ? [(string) $result['sku']] : array_column($result['variations'], 'sku')];
                    } catch (DomainError $e) {
                        $report['errors'][] = ['row' => $groupRows[0]['_row'], 'message' => "{$group}: ".$e->getMessage()];
                    }
                }
                if ($report['errors'] !== [] || ! $apply) {
                    throw new class extends RuntimeException {}; // undo everything: nothing is half imported
                }
            });
            $report['applied'] = true;
        } catch (RuntimeException $e) {
            if (! str_contains($e::class, '@anonymous')) {
                throw $e;
            }
        }

        return $report;
    }

    /** @return list<array<string, string>> rows keyed by column key, plus `_row` (the spreadsheet row number) */
    public function read(string $path, string $extension): array
    {
        $reader = match (strtolower($extension)) {
            'csv', 'txt' => new CsvReader,
            'xlsx' => new XlsxReader,
            default => throw new RuntimeException('Use a .xlsx or .csv file.'),
        };
        $reader->open($path);
        $out = [];
        $map = null;
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $n => $row) {
                    $cells = [];
                    foreach ($row->cells as $i => $cell) {
                        $v = $cell->getValue();
                        $cells[(int) $i] = trim(is_scalar($v) || $v === null ? (string) $v : ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : ''));
                    }
                    ksort($cells);
                    $cells = array_replace(array_fill(0, ($cells === [] ? 0 : max(array_keys($cells)) + 1), ''), $cells);
                    if ($map === null) {
                        $map = $this->headerMap($cells);

                        continue;
                    }
                    if (implode('', $cells) === '') {
                        continue;
                    }
                    $r = ['_row' => (string) $n];
                    foreach ($map as $index => $key) {
                        $r[$key] = $cells[$index] ?? '';
                    }
                    $out[] = $r;
                }
                break; // the first sheet only
            }
        } finally {
            $reader->close();
        }

        return $out;
    }

    /** @param list<string> $headers @return array<int, string> column index => key */
    private function headerMap(array $headers): array
    {
        $byAlias = [];
        foreach (self::COLUMNS as $key => $c) {
            foreach ([$c['header'], ...$c['aliases']] as $alias) {
                $byAlias[$this->norm($alias)] = $key;
            }
        }
        $map = [];
        foreach ($headers as $i => $h) {
            isset($byAlias[$this->norm($h)]) && $map[$i] = $byAlias[$this->norm($h)];
        }
        isset($map) && in_array('name', $map, true) || throw new RuntimeException('The first row must have the column headers (at least "Nombre"). Download the template.');

        return $map;
    }

    private function norm(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower(Str::ascii($s))));
    }

    /** @param list<array<string, string>> $rows @return array<string, list<array<string, string>>> */
    private function groups(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $key = ($r['group'] ?? '') !== '' ? $r['group'] : 'row-'.$r['_row'];
            $out[$key][] = $r;
        }

        return $out;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{product_id: string, type: string, variations: list<array{id: string, sku: string, value_ids: list<string>}>, sku: ?string}
     */
    private function createGroup(string $group, array $rows, ?string $userId, ?callable $onCreated): array
    {
        $first = $rows[0];
        $type = $this->type($first['type'] ?? '');
        $specs = [];
        $states = [];
        $productFields = ['design', 'notes', 'legacy_code', 'packaging', 'base_unit', 'presentation', 'per_presentation', 'weight', 'length', 'width_dim', 'height', 'uses', 'secondary_colors'];
        foreach ($productFields as $f) {
            foreach ($rows as $r) {
                $v = $r[$f] ?? '';
                if ($v !== '' && ($first[$f] ?? '') !== '' && $this->norm($v) !== $this->norm($first[$f])) {
                    throw new InvalidProduct("row {$r['_row']}: {$this->label($f)} differs from the first row of the product; rows of one product share it.");
                }
            }
            [$value, $state] = $this->cell($first[$f] ?? '');
            $state === null || $states[$this->fieldKey($f)] = $state;
        }

        $text = fn (string $f) => $this->cell($first[$f] ?? '')[0];
        $text('design') === null || $specs['design'] = $text('design');
        $text('notes') === null || $specs['notes'] = $text('notes');
        $text('legacy_code') === null || $specs['legacy_code'] = $text('legacy_code');
        $text('packaging') === null || $specs['packaging_type'] = $this->option('packaging_type', $text('packaging'), 'Tipo de empaque');
        $text('base_unit') === null || $specs['base_unit'] = $this->option('unit', $text('base_unit'), 'Unidad base');
        $text('presentation') === null || $specs['presentation_unit'] = $this->option('unit', $text('presentation'), 'Presentación');
        $text('per_presentation') === null || $specs['units_per_presentation'] = $text('per_presentation');
        $text('uses') === null || $specs['uses'] = array_map('trim', explode(',', (string) $text('uses')));
        $text('secondary_colors') === null || $specs['secondary_colors'] = array_map(fn (string $c) => $this->attributeValue('color', trim($c)), explode(',', (string) $text('secondary_colors')));
        if ($text('weight') !== null) {
            $specs['weight'] = ['value' => $text('weight'), 'unit' => $this->option('weight_unit', (string) ($text('weight_unit') ?? 'g'), 'Unidad de peso')];
        }
        foreach (['length' => 'length', 'width_dim' => 'width', 'height' => 'height'] as $col => $key) {
            $text($col) === null || $specs[$key] = ['value' => $text($col), 'unit' => $this->option('length_unit', (string) ($text('dim_unit') ?? 'cm'), 'Unidad de medidas')];
        }

        $axes = [];
        $combos = [];
        $overrides = [];
        $stock = [];
        $material = $this->cell($first['material'] ?? '')[0];
        foreach ($rows as $r) {
            $ids = [];
            foreach (['color' => 'color', 'width' => 'width', 'finish' => 'finish'] as $col => $attr) {
                $label = $this->cell($r[$col] ?? '')[0];
                if ($label !== null) {
                    $ids[$attr] = $this->attributeValue($attr, $label, create: $attr === 'width');
                }
            }
            $axes[] = array_keys($ids);
            $combos[] = array_values($ids);
            $overrides[] = [
                'value_ids' => array_values($ids), 'sku' => ($r['sku'] ?? '') !== '' ? $r['sku'] : null, 'price' => ($r['price'] ?? '') !== '' ? $r['price'] : null,
                'supplier_code' => $this->cell($r['supplier_code'] ?? '')[0], 'notes' => $this->cell($r['variant_notes'] ?? '')[0],
            ];
            if (($r['quantity'] ?? '') !== '' || ($r['location'] ?? '') !== '') {
                $stock[] = ['row' => (int) $r['_row'], 'value_ids' => array_values($ids), 'location' => $r['location'] ?? '', 'quantity' => $r['quantity'] ?? ''];
            }
        }
        count(array_unique(array_map(fn ($a) => implode(',', $a), $axes))) === 1 || throw new InvalidVariation('all the rows of a product need the same variant columns filled (colour, width, finish).');
        $axisNames = $axes[0];
        $variantValues = [];
        foreach ($axisNames as $i => $attr) {
            $variantValues[$this->attributeId($attr)] = array_values(array_unique(array_map(fn ($c) => $c[$i], $combos)));
        }

        $result = $this->creator->create([
            'type_id' => $type['id'], 'names' => array_filter(['es-MX' => $first['name'] ?? '', 'en-US' => $first['name_en'] ?? '']), 'price' => ($first['price'] ?? '') !== '' ? $first['price'] : null,
            'sku' => $axisNames === [] && ($first['sku'] ?? '') !== '' ? $first['sku'] : null, 'specs' => $specs, 'states' => $states,
            'variant_values' => $variantValues, 'combinations' => $axisNames === [] ? null : $combos, 'variants' => $overrides,
            'product_values' => $material === null ? [] : [$this->attributeId('material') => [$this->attributeValue('material', $material)]],
        ] + [], $userId);

        if ($stock !== [] && $onCreated !== null) {
            $lines = [];
            foreach ($stock as $s) {
                $variation = $result['variations'] === [] ? null : collect($result['variations'])->first(fn ($v) => array_diff($s['value_ids'], $v['value_ids']) === [] && array_diff($v['value_ids'], $s['value_ids']) === []);
                $lines[] = ['row' => $s['row'], 'product_id' => $variation['id'] ?? $result['product_id'], 'location' => $s['location'], 'quantity' => $s['quantity']];
            }
            $onCreated($result, $lines);
        }

        return $result;
    }

    /** @return array{0: ?string, 1: ?string} value and state: an empty cell is pending; "n/a" does not apply */
    private function cell(string $raw): array
    {
        $t = trim($raw);
        if ($t === '') {
            return [null, null];
        }
        if (in_array($this->norm($t), self::PENDING, true)) {
            return [null, 'pending'];
        }
        if (in_array($this->norm($t), self::NOT_APPLICABLE, true)) {
            return [null, 'not_applicable'];
        }

        return [$t, null];
    }

    private function fieldKey(string $column): string
    {
        return ['packaging' => 'packaging_type', 'per_presentation' => 'units_per_presentation', 'width_dim' => 'width', 'presentation' => 'presentation_unit'][$column] ?? $column;
    }

    private function label(string $column): string
    {
        return SpecFields::ALL[$this->fieldKey($column)]['label'] ?? $column;
    }

    /** @return array<string, mixed> */
    private function type(string $name): array
    {
        $name === '' && throw new InvalidProduct('the product type is required (column "Tipo de producto").');
        foreach ($this->types->all(false) as $t) {
            if ($this->norm($t['code']) === $this->norm($name) || in_array($this->norm($name), array_map($this->norm(...), $t['names']), true)) {
                return $t;
            }
        }
        throw new InvalidProduct("the product type [{$name}] does not exist.");
    }

    private function option(string $catalog, string $text, string $label): string
    {
        $wanted = $this->norm($text);
        foreach ($this->options->all($catalog, false) as $o) {
            $names = [$o['code'], ...array_values($o['labels']), ...array_values($o['meta']['plural'] ?? [])];
            if (in_array($wanted, array_map(fn ($n) => $this->norm((string) $n), $names), true)) {
                return $o['code'];
            }
        }
        $valid = implode(', ', array_map(fn ($o) => $o['labels'][config('app.locale_default')] ?? $o['code'], $this->options->all($catalog, false)));
        throw new InvalidProduct("{$label}: [{$text}] is not in the list. Valid: {$valid}.");
    }

    /** @var array<string, string> */
    private array $attributeIds = [];

    private function attributeId(string $code): string
    {
        return $this->attributeIds[$code] ??= $this->attributes->idByCode($code) ?? throw new InvalidProduct("The attribute [{$code}] does not exist.");
    }

    /** The id of a value of an attribute, by its name or code; widths may be typed new, the rest must exist. */
    private function attributeValue(string $attribute, string $text, bool $create = false): string
    {
        $attrId = $this->attributeId($attribute);
        $wanted = $this->norm($text);
        $rows = $this->attributes->valuesWithLabels($attrId);
        foreach ($rows as $r) {
            if ($this->norm($r['code']) === $wanted || in_array($wanted, array_map($this->norm(...), $r['labels']), true)) {
                return $r['id'];
            }
        }
        if ($create) {
            return $this->creator->addValueByLabel($attrId, $text);
        }
        $valid = implode(', ', array_unique(array_map(fn (array $r) => $r['labels'][0] ?? $r['code'], $rows)));
        throw new InvalidProduct("[{$text}] is not a {$attribute} of the catalogue. Valid: {$valid}.");
    }
}
