<?php

declare(strict_types=1);

namespace Cronos\Catalog\Application;

use Cronos\Catalog\Domain\Exceptions\InvalidProduct;
use Cronos\Catalog\Domain\Exceptions\InvalidVariation;
use Cronos\Catalog\Domain\Exceptions\NotFound;
use Cronos\Catalog\Domain\Ports\AttributeRepository;
use Cronos\Catalog\Domain\Ports\CatalogOptionRepository;
use Cronos\Catalog\Domain\Ports\ProductRepository;
use Cronos\Catalog\Domain\Ports\ProductTypeRepository;
use Cronos\Catalog\Domain\Services\VariationCombination;
use Cronos\Catalog\Domain\ValueObjects\Slug;
use Illuminate\Support\Str;

/**
 * Registers a product the way the client thinks about it: pick the type, name it, tick the colours and widths, and every combination
 * becomes a variation with its own consecutive SKU (and its permanent warehouse code). One transaction: all of it or nothing.
 */
final class QuickProductCreator
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductTypeRepository $types,
        private readonly ProductManager $manager,
        private readonly VariationManager $variations,
        private readonly AttributeManager $attributeManager,
        private readonly AttributeRepository $attributes,
        private readonly CatalogOptionRepository $options,
        private readonly ProductSpecs $specs,
        private readonly SkuGenerator $skus,
    ) {}

    /**
     * @param  array<string, mixed>  $in  type_id, names{locale: name}, short_description{locale: text}, category_ids, tag_ids, price, specs, states, sku (simple only),
     *                                    variant_values{attributeId: [valueId]}, new_values{attributeId: [{label, hex?}]}, product_values{attributeId: [valueId]},
     *                                    variants[{value_ids, price?, supplier_code?, notes?}], roll_price
     * @return array{product_id: string, type: string, variations: list<array{id: string, sku: string, value_ids: list<string>}>, sku: ?string}
     */
    public function create(array $in, ?string $userId = null): array
    {
        $type = $this->types->find((string) ($in['type_id'] ?? '')) ?? throw new NotFound('The product type');
        $type['is_active'] || throw new InvalidProduct('That product type is deactivated.');

        return $this->products->atomically(fn (): array => $this->build($type, $in, $userId));
    }

    /**
     * @param  array<string, mixed>  $type
     * @param  array<string, mixed>  $in
     * @return array{product_id: string, type: string, variations: list<array{id: string, sku: string, value_ids: list<string>}>, sku: ?string}
     */
    private function build(array $type, array $in, ?string $userId): array
    {
        $variantAttrs = $type['variant_attribute_ids'];
        $picked = $this->pickValues((array) ($in['variant_values'] ?? []), (array) ($in['new_values'] ?? []), $variantAttrs, 'variant');
        $productPicked = $this->pickValues((array) ($in['product_values'] ?? []), [], $type['product_attribute_ids'], 'product');
        // The type says which attributes MAY define variants; the product is defined by the ones that were actually picked (a ribbon in one finish does not need that axis).
        $used = array_values(array_filter($variantAttrs, fn (string $a) => ($picked[$a] ?? []) !== []));
        $withVariants = $used !== [];

        $specs = (array) ($in['specs'] ?? []);
        $sales = $this->sales($type, $specs, $in);
        $names = array_filter(array_map(fn ($n) => trim((string) $n), (array) ($in['names'] ?? [])), fn ($n) => $n !== '');
        $translations = [];
        foreach ($names as $locale => $name) {
            $translations[$locale] = ['name' => $name, 'slug' => $this->freeSlug((string) $locale, $name)] + (isset($in['short_description'][$locale]) ? ['short_description' => $in['short_description'][$locale]] : []);
        }

        $productId = $this->manager->create([
            'type' => $withVariants ? 'variable' : 'simple', 'sku' => $withVariants ? null : ($in['sku'] ?? $this->skus->next($type['sku_prefix'])), 'price' => $in['price'] ?? null,
            'sales' => $sales, 'translations' => $translations, 'category_ids' => $in['category_ids'] ?? [], 'tag_ids' => $in['tag_ids'] ?? [],
        ]);

        $attributes = [];
        foreach ($used as $a) {
            $attributes[] = ['attribute_id' => $a, 'is_variation' => true];
        }
        foreach (array_keys($productPicked) as $a) {
            $attributes[] = ['attribute_id' => $a, 'is_variation' => false];
        }
        $attributes === [] || $this->manager->setAttributes($productId, $attributes);
        $offered = array_merge(...array_values($withVariants ? $picked : []), ...array_values($productPicked));
        $offered === [] || $this->manager->setOfferedValues($productId, array_values(array_unique($offered)));

        $this->specs->update($productId, $this->productSpecs($specs), (array) ($in['states'] ?? []), $type['id'], true, $userId);

        $created = [];
        if ($withVariants) {
            $overrides = $this->overrides((array) ($in['variants'] ?? []));
            $combos = VariationCombination::cartesian(array_map(fn (string $a) => $picked[$a], $used));
            count($combos) <= VariationManager::MAX_GENERATED || throw new InvalidVariation('too many combinations ('.count($combos).', limit '.VariationManager::MAX_GENERATED.').');
            foreach ($combos as $valueIds) {
                $o = $overrides[VariationCombination::hash($valueIds)] ?? [];
                $id = $this->variations->create($productId, ['sku' => $o['sku'] ?? $this->skus->next($type['sku_prefix']), 'value_ids' => $valueIds, 'price' => $o['price'] ?? null]);
                $vs = array_filter(['supplier_code' => $o['supplier_code'] ?? null, 'variant_notes' => $o['notes'] ?? null], fn ($v) => $v !== null && $v !== '');
                $vs === [] || $this->specs->update($id, $vs, [], null, false, $userId);
                $created[] = ['id' => $id, 'sku' => (string) $this->products->find($id)['sku'], 'value_ids' => $valueIds];
            }
        }

        return ['product_id' => $productId, 'type' => $withVariants ? 'variable' : 'simple', 'variations' => $created, 'sku' => $withVariants ? null : (string) $this->products->find($productId)['sku']];
    }

    /**
     * Existing values by id plus new ones typed in the wizard ("25 mm", "Verde olivo"), which are added to the attribute on the spot.
     *
     * @param  array<string, list<string>>  $existing
     * @param  array<string, list<array<string, mixed>>>  $new
     * @param  list<string>  $allowed
     * @return array<string, list<string>>
     */
    private function pickValues(array $existing, array $new, array $allowed, string $role): array
    {
        $out = [];
        foreach (array_unique([...array_keys($existing), ...array_keys($new)]) as $attributeId) {
            in_array($attributeId, $allowed, true) || throw new InvalidProduct("That attribute does not belong to the {$role} attributes of this product type.");
            $ids = array_map('strval', (array) ($existing[$attributeId] ?? []));
            foreach ((array) ($new[$attributeId] ?? []) as $n) {
                $label = trim((string) ($n['label'] ?? ''));
                $label !== '' || throw new InvalidProduct('A new value needs a name.');
                $code = substr(Str::slug($label, '_'), 0, 40) ?: throw new InvalidProduct('A new value needs letters or digits in its name.');
                $found = $this->attributes->valueIdByCode($attributeId, $code);
                $ids[] = $found ?? $this->addValue($attributeId, $code, $label, $n['hex'] ?? null);
            }
            $out[$attributeId] = array_values(array_unique($ids));
        }

        return array_filter($out, fn (array $v) => $v !== []);
    }

    private function addValue(string $attributeId, string $code, string $label, mixed $hex): string
    {
        $type = $this->attributes->find($attributeId)['type'] ?? throw new NotFound('The attribute');
        $this->attributeManager->addValue($attributeId, $code, $type === 'color' ? ['hex' => (string) $hex] : null, 99, ['es-MX' => $label, 'en-US' => $label]);

        return (string) $this->attributes->valueIdByCode($attributeId, $code);
    }

    /**
     * The base unit decides how the shop sells it (metres are cut, the rest are counted); a roll of N metres turns on whole-roll sales.
     *
     * @param  array<string, mixed>  $type
     * @param  array<string, mixed>  $specs
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function sales(array $type, array $specs, array $in): array
    {
        $sales = $type['sales'];
        $base = isset($specs['base_unit']) ? ($this->options->findByCode('unit', (string) $specs['base_unit'])['meta'] ?? null) : null;
        if ($base !== null) {
            $sales['unit'] = $base['sell_unit'];
        }
        if (($sales['unit'] ?? 'piece') === 'piece') {
            return ['unit' => 'piece'];
        }
        $presentation = isset($specs['presentation_unit']) ? ($this->options->findByCode('unit', (string) $specs['presentation_unit'])['meta'] ?? null) : null;
        $per = $specs['units_per_presentation'] ?? null;
        $roll = ($presentation['roll'] ?? false) && $per !== null && (float) $per > 0;
        $cut = (bool) ($sales['sell_by_cut'] ?? true);

        return [
            'unit' => 'meter', 'step' => $sales['step'] ?? '0.5', 'min' => $sales['min'] ?? '1', 'sell_by_cut' => $roll ? $cut : true, 'sell_by_roll' => $roll,
            'roll_length' => $roll ? (string) $per : null, 'roll_price' => $roll ? ($in['roll_price'] ?? null) : null,
        ];
    }

    /** @param array<string, mixed> $specs @return array<string, mixed> only the product-level fields (the rest belongs to variants) */
    private function productSpecs(array $specs): array
    {
        return array_diff_key($specs, array_flip(['supplier_code', 'variant_notes']));
    }

    /** @param list<array<string, mixed>> $rows @return array<string, array<string, mixed>> by combination hash */
    private function overrides(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $ids = array_map('strval', (array) ($r['value_ids'] ?? []));
            $ids === [] || $out[VariationCombination::hash($ids)] = $r;
        }

        return $out;
    }

    private function freeSlug(string $locale, string $name): string
    {
        $base = (string) Slug::from($name);
        $slug = $base;
        for ($i = 2; $this->products->slugTaken($locale, $slug); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
