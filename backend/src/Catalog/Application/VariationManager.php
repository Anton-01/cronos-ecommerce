<?php

declare(strict_types=1);

namespace Cronos\Catalog\Application;

use Cronos\Catalog\Domain\Exceptions\InvalidVariation;
use Cronos\Catalog\Domain\Exceptions\NotFound;
use Cronos\Catalog\Domain\Exceptions\SkuTaken;
use Cronos\Catalog\Domain\Ports\AttributeRepository;
use Cronos\Catalog\Domain\Ports\ProductRepository;
use Cronos\Catalog\Domain\Services\VariationCombination;

final class VariationManager
{
    /** Safety net against combinatorial explosions (5 colours x 8 widths x 6 materials...). */
    public const MAX_GENERATED = 200;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly AttributeRepository $attributes,
    ) {}

    /** @param array{sku: string, value_ids: list<string>, price?: mixed, weight?: mixed, dimensions?: ?array<string, mixed>, barcode?: ?string} $input */
    public function create(string $parentId, array $input): string
    {
        $parent = $this->parent($parentId);
        $valueIds = $this->validCombination($parent, $input['value_ids'] ?? []);
        $hash = VariationCombination::hash($valueIds);
        ! $this->products->combinationExists($parentId, $hash) || throw new InvalidVariation('this combination already exists.');

        $sku = $this->sku($input['sku'] ?? null);
        ! $this->products->skuTaken($sku) || throw new SkuTaken($sku);

        return $this->products->createVariation($parentId, [
            'sku' => $sku, 'status' => 'draft', 'barcode' => isset($input['barcode']) ? trim((string) $input['barcode']) ?: null : null,
            'price' => Input::money($input['price'] ?? null), 'weight' => Input::weight($input['weight'] ?? null), 'dimensions' => Input::dimensions($input['dimensions'] ?? null),
            'position' => $this->products->nextVariationPosition($parentId),
        ], $valueIds, $hash);
    }

    /**
     * Only the keys present are touched; a null value means "inherit from the parent again".
     *
     * @param  array<string, mixed>  $input  sku, barcode, price, weight, dimensions, status
     */
    public function update(string $id, array $input): void
    {
        $row = $this->products->find($id) ?? throw new NotFound('The variation');
        $row['type'] === 'variation' || throw new InvalidVariation('this product is not a variation.');

        $columns = [];
        if (array_key_exists('sku', $input)) {
            $sku = $this->sku($input['sku']);
            ! $this->products->skuTaken($sku, $id) || throw new SkuTaken($sku);
            $columns['sku'] = $sku;
        }
        array_key_exists('barcode', $input) && $columns['barcode'] = $input['barcode'] === null ? null : (trim((string) $input['barcode']) ?: null);
        array_key_exists('price', $input) && $columns['price'] = Input::money($input['price']);
        array_key_exists('weight', $input) && $columns['weight'] = Input::weight($input['weight']);
        array_key_exists('dimensions', $input) && $columns['dimensions'] = Input::dimensions($input['dimensions']);
        array_key_exists('position', $input) && $columns['position'] = (int) $input['position'];

        $columns === [] || $this->products->update($id, $columns);
    }

    public function delete(string $id): void
    {
        $row = $this->products->find($id) ?? throw new NotFound('The variation');
        $row['type'] === 'variation' || throw new InvalidVariation('this product is not a variation.');

        $this->products->delete($id);
    }

    /**
     * Creates every missing combination of the offered values as a draft variation that inherits everything.
     *
     * @return list<string> ids of the variations created
     */
    public function generate(string $parentId): array
    {
        $parent = $this->parent($parentId);
        $variationAttributes = array_column(array_filter($parent['attributes'], fn ($a) => $a['is_variation']), 'attribute_id');
        $variationAttributes !== [] || throw new InvalidVariation('mark at least one attribute as a variation attribute first.');

        $owners = $this->attributes->attributeIdsOfValues($parent['value_ids']);
        $options = array_fill_keys($variationAttributes, []);
        foreach ($parent['value_ids'] as $valueId) {
            if (isset($owners[$valueId], $options[$owners[$valueId]])) {
                $options[$owners[$valueId]][] = $valueId;
            }
        }
        in_array([], $options, true) && throw new InvalidVariation('every variation attribute needs at least one offered value.');

        $combinations = VariationCombination::cartesian($options);
        count($combinations) <= self::MAX_GENERATED || throw new InvalidVariation('too many combinations ('.count($combinations).', limit '.self::MAX_GENERATED.').');

        $created = [];
        foreach ($combinations as $valueIds) {
            $hash = VariationCombination::hash($valueIds);
            if ($this->products->combinationExists($parentId, $hash)) {
                continue;
            }
            $created[] = $this->products->createVariation($parentId, [
                'sku' => $this->uniqueSku($parent, $valueIds), 'status' => 'draft', 'position' => $this->products->nextVariationPosition($parentId),
            ], $valueIds, $hash);
        }

        return $created;
    }

    /** @return array<string, mixed> */
    private function parent(string $id): array
    {
        $parent = $this->products->find($id) ?? throw new NotFound('The product');
        $parent['type'] === 'variable' || throw new InvalidVariation('only variable products have variations.');

        return $parent;
    }

    /**
     * A variation picks exactly one offered value for every variation attribute of its parent.
     *
     * @param  array<string, mixed>  $parent
     * @param  list<string>  $valueIds
     * @return list<string>
     */
    private function validCombination(array $parent, array $valueIds): array
    {
        $valueIds = array_values(array_unique($valueIds));
        $required = array_column(array_filter($parent['attributes'], fn ($a) => $a['is_variation']), 'attribute_id');
        $required !== [] || throw new InvalidVariation('the product has no variation attributes yet.');

        $owners = $this->attributes->attributeIdsOfValues($valueIds);
        count($owners) === count($valueIds) || throw new InvalidVariation('an attribute value does not exist.');

        $picked = array_values($owners);
        sort($picked);
        $expected = $required;
        sort($expected);
        $picked === $expected || throw new InvalidVariation('choose exactly one value for each variation attribute.');

        foreach ($valueIds as $valueId) {
            in_array($valueId, $parent['value_ids'], true) || throw new InvalidVariation('a chosen value is not offered by this product.');
        }

        return $valueIds;
    }

    private function sku(mixed $sku): string
    {
        $sku = trim((string) $sku);
        (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-\/]{0,63}$/', $sku) || throw new InvalidVariation('a valid SKU is required.');

        return $sku;
    }

    /** @param array<string, mixed> $parent @param list<string> $valueIds */
    private function uniqueSku(array $parent, array $valueIds): string
    {
        $base = $parent['sku'] ?: strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($parent['translations'][Localized::default()]['slug'] ?? $parent['id'])), 0, 12));
        $byId = $this->attributes->codesOfValues($valueIds);
        $codes = array_map(fn (string $id) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $byId[$id] ?? substr($id, -4))), $valueIds);
        $candidate = substr($base.'-'.implode('-', $codes), 0, 64);

        for ($n = 2; $this->products->skuTaken($candidate); $n++) {
            $candidate = substr($base.'-'.implode('-', $codes), 0, 60).'-'.$n;
        }

        return $candidate;
    }
}
