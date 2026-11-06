<?php

declare(strict_types=1);

namespace Cronos\Catalog\Interfaces\GraphQL;

use Cronos\Catalog\Application\CatalogOptionManager;
use Cronos\Catalog\Application\ProductSpecs;
use Cronos\Catalog\Application\ProductTypeManager;
use Cronos\Catalog\Application\QuickProductCreator;
use Cronos\Catalog\Domain\Ports\CatalogOptionRepository;
use Cronos\Catalog\Domain\Ports\ProductSpecStore;
use Cronos\Catalog\Domain\Ports\ProductTypeRepository;
use Cronos\Catalog\Domain\Services\SpecFields;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/** Short lists, product types and data sheets for the admin. */
final class CatalogDataApi
{
    public function __construct(
        private readonly CatalogOptionManager $lists,
        private readonly CatalogOptionRepository $options,
        private readonly ProductTypeManager $typeManager,
        private readonly ProductTypeRepository $types,
        private readonly ProductSpecs $specs,
        private readonly ProductSpecStore $specStore,
        private readonly QuickProductCreator $quick,
    ) {}

    public function lists(): array
    {
        return array_map(fn (string $code, string $label) => ['code' => $code, 'label' => $label], array_keys(CatalogOptionManager::CATALOGS), CatalogOptionManager::CATALOGS);
    }

    /** @param array<string, mixed> $a */
    public function options(mixed $root, array $a): array
    {
        return array_map($this->option(...), $this->lists->list($a['catalog'], (bool) ($a['includeInactive'] ?? true)));
    }

    /** @param array<string, mixed> $a */
    public function createOption(mixed $root, array $a): array
    {
        $id = $this->lists->create($a['catalog'], $a['code'], Convert::texts($a['labels']), $a['meta'] ?? null, (int) ($a['position'] ?? 0));

        return $this->oneOption($id);
    }

    /** @param array<string, mixed> $a */
    public function updateOption(mixed $root, array $a): array
    {
        $this->lists->update((int) $a['id'], isset($a['labels']) ? Convert::texts($a['labels']) : null, $a['meta'] ?? null, isset($a['position']) ? (int) $a['position'] : null, $a['isActive'] ?? null);

        return $this->oneOption((int) $a['id']);
    }

    /** @param array<string, mixed> $a */
    public function deleteOption(mixed $root, array $a): bool
    {
        $this->lists->delete((int) $a['id']);

        return true;
    }

    /** @param array<string, mixed> $a */
    public function types(mixed $root, array $a): array
    {
        return array_map($this->type(...), $this->typeManager->list((bool) ($a['includeInactive'] ?? true)));
    }

    /** @param array<string, mixed> $a */
    public function createType(mixed $root, array $a): array
    {
        return $this->type($this->types->find($this->typeManager->create($this->typeInput($a['input']))));
    }

    /** @param array<string, mixed> $a */
    public function updateType(mixed $root, array $a): array
    {
        $this->typeManager->update($a['id'], $this->typeInput($a['input']));

        return $this->type($this->types->find($a['id']));
    }

    /** @param array<string, mixed> $a */
    public function deleteType(mixed $root, array $a): bool
    {
        $this->typeManager->delete($a['id']);

        return true;
    }

    /** @param array<string, mixed> $a */
    public function quickCreate(mixed $root, array $a, GraphQLContext $c): array
    {
        $in = $a['input'];
        $r = $this->quick->create([
            'type_id' => $in['typeId'], 'names' => Convert::texts($in['names']), 'short_description' => isset($in['shortDescription']) ? Convert::texts($in['shortDescription']) : [], 'category_ids' => $in['categoryIds'] ?? [],
            'tag_ids' => $in['tagIds'] ?? [], 'price' => $in['price'] ?? null, 'roll_price' => $in['rollPrice'] ?? null, 'sku' => ($in['sku'] ?? '') !== '' ? $in['sku'] : null, 'specs' => (array) ($in['specs'] ?? []),
            'states' => (array) ($in['states'] ?? []), 'variant_values' => (array) ($in['variantValues'] ?? []), 'new_values' => (array) ($in['newValues'] ?? []), 'product_values' => (array) ($in['productValues'] ?? []),
            'variants' => array_map(fn (array $v) => ['value_ids' => $v['valueIds'] ?? [], 'sku' => $v['sku'] ?? null, 'price' => $v['price'] ?? null, 'supplier_code' => $v['supplierCode'] ?? null, 'notes' => $v['notes'] ?? null], (array) ($in['variants'] ?? [])),
        ], (string) $c->user()?->getAuthIdentifier());

        return ['productId' => $r['product_id'], 'type' => $r['type'], 'sku' => $r['sku'], 'variations' => array_map(fn (array $v) => ['id' => $v['id'], 'sku' => $v['sku'], 'valueIds' => $v['value_ids']], $r['variations'])];
    }

    public function specFields(): array
    {
        return array_map(fn (string $key) => $this->definition($key), array_keys(SpecFields::ALL));
    }

    /** @param array<string, mixed> $a */
    public function specs(mixed $root, array $a): array
    {
        return $this->sheet($this->specs->get($a['productId']));
    }

    /** @param array<string, mixed> $a */
    public function updateSpecs(mixed $root, array $a, GraphQLContext $c): array
    {
        $sheet = $this->specs->update($a['productId'], (array) ($a['specs'] ?? []), (array) ($a['states'] ?? []), $a['typeId'] ?? null, (bool) ($a['setType'] ?? false), (string) $c->user()?->getAuthIdentifier());

        return $this->sheet($sheet);
    }

    /** @param array<string, mixed> $a */
    public function missingData(mixed $root, array $a): array
    {
        $types = array_column($this->typeManager->list(true), 'names', 'id');
        $locale = config('app.locale_default');

        return array_map(fn (array $r) => [
            'productId' => $r['product_id'], 'sku' => $r['sku'], 'name' => $r['name'], 'status' => $r['status'], 'typeName' => $types[$r['type_id']][$locale] ?? null,
            'missing' => array_map(fn (string $f) => SpecFields::ALL[$f]['label'], $r['missing']),
        ], $this->specStore->withMissing(max(1, min((int) ($a['first'] ?? 200), 500))));
    }

    /** @param array<string, mixed> $sheet @return array<string, mixed> */
    private function sheet(array $sheet): array
    {
        $fields = [];
        foreach (SpecFields::ALL as $key => $def) {
            $fields[] = $this->definition($key) + ['required' => in_array($key, $sheet['required'], true), 'state' => $sheet['states'][$key], 'value' => $sheet['specs'][$key] ?? null];
        }

        return ['productId' => $sheet['product_id'], 'typeId' => $sheet['type_id'], 'fields' => $fields, 'missing' => array_map(fn (string $f) => SpecFields::ALL[$f]['label'], $sheet['missing'])];
    }

    /** @return array<string, mixed> */
    private function definition(string $key): array
    {
        $d = SpecFields::ALL[$key];

        return ['key' => $key, 'label' => $d['label'], 'kind' => $d['kind'], 'scope' => $d['scope'], 'group' => $d['group'], 'catalog' => $d['catalog'] ?? $d['unit_catalog'] ?? null];
    }

    /** @param array<string, mixed> $o @return array<string, mixed> */
    private function option(array $o): array
    {
        return ['id' => $o['id'], 'catalog' => $o['catalog'], 'code' => $o['code'], 'labels' => $this->texts($o['labels']), 'meta' => $o['meta'], 'position' => $o['position'], 'isActive' => $o['is_active'], 'inUse' => $o['in_use'] ?? false];
    }

    /** @return array<string, mixed> */
    private function oneOption(int $id): array
    {
        $o = $this->options->find($id);

        return $this->option($o + ['in_use' => $this->options->isInUse($o['catalog'], $o['code'])]);
    }

    /** @param array<string, mixed> $t @return array<string, mixed> */
    private function type(array $t): array
    {
        return ['id' => $t['id'], 'code' => $t['code'], 'names' => $this->texts($t['names']), 'skuPrefix' => $t['sku_prefix'], 'sales' => $t['sales'], 'requiredFields' => $t['required_fields'], 'variantAttributeIds' => $t['variant_attribute_ids'],
            'productAttributeIds' => $t['product_attribute_ids'], 'isActive' => $t['is_active'], 'position' => $t['position']];
    }

    /** @param array<string, string> $map @return list<array{locale: string, value: string}> */
    private function texts(array $map): array
    {
        return array_map(fn (string $l, string $v) => ['locale' => $l, 'value' => $v], array_keys($map), $map);
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    private function typeInput(array $in): array
    {
        $out = [];
        foreach (['code', 'skuPrefix', 'sales', 'requiredFields', 'variantAttributeIds', 'productAttributeIds', 'isActive', 'position'] as $k) {
            array_key_exists($k, $in) && $out[Str::snake($k)] = $in[$k];
        }
        isset($in['names']) && $out['names'] = Convert::texts($in['names']);

        return $out;
    }
}
