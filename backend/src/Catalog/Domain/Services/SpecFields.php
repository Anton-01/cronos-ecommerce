<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Services;

/** The fields of a product's data sheet (what the client fills in beside the sales data). Pure description: validation lives in ProductSpecs. */
final class SpecFields
{
    public const TEXT = 'text';

    public const OPTION = 'option';

    public const MEASURE = 'measure';

    public const NUMBER = 'number';

    public const COLORS = 'colors';

    public const TAGS = 'tags';

    /** @var array<string, array{label: string, kind: string, scope: string, catalog?: string, unit_catalog?: string, max?: int, group: string}> */
    public const ALL = [
        'design' => ['label' => 'Diseño o modelo', 'kind' => self::TEXT, 'scope' => 'product', 'max' => 120, 'group' => 'features'],
        'secondary_colors' => ['label' => 'Colores secundarios', 'kind' => self::COLORS, 'scope' => 'product', 'group' => 'features'],
        'packaging_type' => ['label' => 'Tipo de empaque', 'kind' => self::OPTION, 'scope' => 'product', 'catalog' => 'packaging_type', 'group' => 'sku'],
        'weight' => ['label' => 'Peso', 'kind' => self::MEASURE, 'scope' => 'product', 'unit_catalog' => 'weight_unit', 'group' => 'sku'],
        'length' => ['label' => 'Largo del producto', 'kind' => self::MEASURE, 'scope' => 'product', 'unit_catalog' => 'length_unit', 'group' => 'sku'],
        'width' => ['label' => 'Ancho del producto', 'kind' => self::MEASURE, 'scope' => 'product', 'unit_catalog' => 'length_unit', 'group' => 'sku'],
        'height' => ['label' => 'Alto del producto', 'kind' => self::MEASURE, 'scope' => 'product', 'unit_catalog' => 'length_unit', 'group' => 'sku'],
        'base_unit' => ['label' => 'Unidad base', 'kind' => self::OPTION, 'scope' => 'product', 'catalog' => 'unit', 'group' => 'unit'],
        'presentation_unit' => ['label' => 'Presentación', 'kind' => self::OPTION, 'scope' => 'product', 'catalog' => 'unit', 'group' => 'unit'],
        'units_per_presentation' => ['label' => 'Contenido por presentación', 'kind' => self::NUMBER, 'scope' => 'product', 'group' => 'unit'],
        'uses' => ['label' => 'Usos o aplicaciones', 'kind' => self::TAGS, 'scope' => 'product', 'max' => 20, 'group' => 'extra'],
        'notes' => ['label' => 'Observaciones del producto', 'kind' => self::TEXT, 'scope' => 'product', 'max' => 500, 'group' => 'extra'],
        'legacy_code' => ['label' => 'Código anterior', 'kind' => self::TEXT, 'scope' => 'product', 'max' => 64, 'group' => 'extra'],
        'supplier_code' => ['label' => 'Código de proveedor', 'kind' => self::TEXT, 'scope' => 'variant', 'max' => 64, 'group' => 'variant'],
        'variant_notes' => ['label' => 'Observaciones de la variante', 'kind' => self::TEXT, 'scope' => 'variant', 'max' => 500, 'group' => 'variant'],
    ];

    public const STATES = ['pending', 'not_applicable'];

    /** @return list<string> */
    public static function keys(?string $scope = null): array
    {
        return array_keys(array_filter(self::ALL, fn (array $f) => $scope === null || $f['scope'] === $scope));
    }

    /** Fields that may be marked mandatory for a product type. @return list<string> */
    public static function requirable(): array
    {
        return array_keys(self::ALL);
    }

    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || (is_array($value) && array_key_exists('value', $value) && ($value['value'] === null || $value['value'] === ''));
    }
}
