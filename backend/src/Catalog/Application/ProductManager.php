<?php

declare(strict_types=1);

namespace Cronos\Catalog\Application;

use Cronos\Catalog\Domain\Exceptions\InUse;
use Cronos\Catalog\Domain\Exceptions\InvalidProduct;
use Cronos\Catalog\Domain\Exceptions\NotFound;
use Cronos\Catalog\Domain\Exceptions\SkuTaken;
use Cronos\Catalog\Domain\Exceptions\SlugTaken;
use Cronos\Catalog\Domain\Ports\AttributeRepository;
use Cronos\Catalog\Domain\Ports\CategoryRepository;
use Cronos\Catalog\Domain\Ports\PreviewTokenStore;
use Cronos\Catalog\Domain\Ports\ProductRepository;
use Cronos\Catalog\Domain\Ports\TagRepository;
use Cronos\Catalog\Domain\Services\PublishingPolicy;
use Cronos\Catalog\Domain\ValueObjects\SalesUnit;

final class ProductManager
{
    private const TEXT = ['short_description', 'description', 'meta_title', 'meta_description'];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly CategoryRepository $categories,
        private readonly TagRepository $tags,
        private readonly AttributeRepository $attributes,
        private readonly PublishingPolicy $policy,
        private readonly PreviewTokenStore $previews,
    ) {}

    /**
     * @param  array<string, mixed>  $input  type, sku, barcode, price, weight, dimensions, sales{...}, stock flags,
     *                                       is_customizable, translations, category_ids, primary_category_id, tag_ids
     */
    public function create(array $input): string
    {
        $type = (string) ($input['type'] ?? '');
        in_array($type, ['simple', 'variable', 'grouped'], true) || throw new InvalidProduct('The product type must be simple, variable or grouped (variations are created from a variable product).');

        $translations = Localized::normalise($input['translations'] ?? [], self::TEXT);
        $this->assertSlugsFree($translations);
        $sku = $this->cleanSku($input['sku'] ?? null);
        $sku === null || ! $this->products->skuTaken($sku) || throw new SkuTaken($sku);
        // Grouped products only bundle others: they carry no sales configuration or price of their own.
        $sales = $type === 'grouped' ? SalesUnit::pieces() : Input::salesUnit($input['sales'] ?? null);
        $this->assertCategoriesAndTagsExist($input['category_ids'] ?? [], $input['tag_ids'] ?? []);

        $id = $this->products->create([
            'type' => $type, 'status' => 'draft', 'sku' => $sku, 'barcode' => $this->clean($input['barcode'] ?? null, 64),
            'tax_class_id' => $this->taxClass($input['tax_class_id'] ?? null),
            'price' => $type === 'grouped' ? null : Input::money($input['price'] ?? null),
            'weight' => Input::weight($input['weight'] ?? null), 'dimensions' => Input::dimensions($input['dimensions'] ?? null),
            ...$sales->toColumns(), ...$this->stockColumns($input), 'is_customizable' => (bool) ($input['is_customizable'] ?? false),
        ], array_map(fn (array $t) => $t, $translations));

        $this->syncTaxonomy($id, $input);

        return $id;
    }

    /** @param array<string, mixed> $input partial: only the keys present are changed */
    public function update(string $id, array $input): void
    {
        $row = $this->products->find($id) ?? throw new NotFound('The product');
        $row['type'] !== 'variation' || throw new InvalidProduct('Edit variations from their own form.');

        $columns = [];
        if (array_key_exists('sku', $input)) {
            $sku = $this->cleanSku($input['sku']);
            $sku === null || ! $this->products->skuTaken($sku, $id) || throw new SkuTaken($sku);
            $columns['sku'] = $sku;
        }
        array_key_exists('barcode', $input) && $columns['barcode'] = $this->clean($input['barcode'], 64);
        array_key_exists('tax_class_id', $input) && $columns['tax_class_id'] = $this->taxClass($input['tax_class_id']);
        array_key_exists('price', $input) && $columns['price'] = $row['type'] === 'grouped' ? null : Input::money($input['price']);
        array_key_exists('weight', $input) && $columns['weight'] = Input::weight($input['weight']);
        array_key_exists('dimensions', $input) && $columns['dimensions'] = Input::dimensions($input['dimensions']);
        array_key_exists('is_customizable', $input) && $columns['is_customizable'] = (bool) $input['is_customizable'];
        $columns += $this->stockColumns($input, partial: true);

        if (isset($input['sales']) && $row['type'] !== 'grouped') {
            // Merge over the current configuration so a partial edit cannot create an invalid one.
            $merged = array_replace(SalesUnitInput::fromColumns($row['sales']), $input['sales']);
            $columns += Input::salesUnit($merged)->toColumns();
        }

        $translations = null;
        if (isset($input['translations'])) {
            $translations = Localized::normalise($input['translations'], self::TEXT, requireDefault: false);
            $this->assertSlugsFree($translations, $id);
        }

        $this->assertStillPublishable($row, $columns, $translations);

        $columns === [] || $this->products->update($id, $columns);
        $translations === null || $this->products->saveTranslations($id, $translations);
        $this->syncTaxonomy($id, $input);
    }

    public function delete(string $id): void
    {
        $this->products->find($id) ?? throw new NotFound('The product');

        $this->products->delete($id);
    }

    /** Makes the product visible to customers, or explains everything that is still missing. */
    public function publish(string $id): void
    {
        $row = $this->products->find($id) ?? throw new NotFound('The product');

        $this->policy->assertPublishable($this->snapshot($row));
        $this->products->markPublished($id, 'published');
    }

    /** @param 'draft'|'inactive' $status */
    public function unpublish(string $id, string $status = 'inactive'): void
    {
        in_array($status, ['draft', 'inactive'], true) || throw new InvalidProduct('Use draft or inactive.');
        $this->products->find($id) ?? throw new NotFound('The product');

        $this->products->markPublished($id, $status);
    }

    /** @param array{attribute_id: string, is_variation?: bool, is_visible?: bool}[] $attributes */
    public function setAttributes(string $id, array $attributes): void
    {
        $row = $this->products->find($id) ?? throw new NotFound('The product');
        in_array($row['type'], ['simple', 'variable'], true) || throw new InvalidProduct('Only simple and variable products have attributes.');

        $clean = [];
        foreach ($attributes as $a) {
            $this->attributes->find($a['attribute_id']) ?? throw new NotFound('One of the attributes');
            $isVariation = (bool) ($a['is_variation'] ?? false);
            ! ($isVariation && $row['type'] !== 'variable') || throw new InvalidProduct('Only variable products can use variation attributes.');
            $clean[$a['attribute_id']] = ['attribute_id' => $a['attribute_id'], 'is_variation' => $isVariation, 'is_visible' => (bool) ($a['is_visible'] ?? true)];
        }

        // Variations are defined by the variation attributes: they cannot change underneath existing variations.
        $before = array_column(array_filter($row['attributes'], fn ($a) => $a['is_variation']), 'attribute_id');
        $after = array_keys(array_filter($clean, fn ($a) => $a['is_variation']));
        sort($before);
        sort($after);
        $this->products->variationsOf($id) === [] || $before === $after || throw new InUse('The variation attributes (delete the variations first)');

        // Offered values of attributes that were removed no longer make sense.
        $keep = array_keys($clean);
        $valueAttr = $this->attributes->attributeIdsOfValues($row['value_ids']);
        $this->products->setAttributes($id, array_values($clean));
        $this->products->setValues($id, array_keys(array_filter($valueAttr, fn ($attrId) => in_array($attrId, $keep, true))));
    }

    /** @param list<string> $valueIds values on offer for the product's attributes */
    public function setOfferedValues(string $id, array $valueIds): void
    {
        $row = $this->products->find($id) ?? throw new NotFound('The product');
        in_array($row['type'], ['simple', 'variable'], true) || throw new InvalidProduct('Only simple and variable products have attributes.');

        $valueIds = array_values(array_unique($valueIds));
        $owners = $this->attributes->attributeIdsOfValues($valueIds);
        $assigned = array_column($row['attributes'], 'attribute_id');

        foreach ($valueIds as $valueId) {
            isset($owners[$valueId]) || throw new NotFound('One of the attribute values');
            in_array($owners[$valueId], $assigned, true) || throw new InvalidProduct('A value belongs to an attribute the product does not use.');
        }

        // A value that an existing variation is built on cannot be withdrawn.
        $removed = array_diff($row['value_ids'], $valueIds);
        foreach ($this->products->variationsOf($id) as $variation) {
            array_intersect($variation['value_ids'], $removed) === [] || throw new InUse('A value used by an existing variation');
        }

        $this->products->setValues($id, $valueIds);
    }

    /** @param list<array{product_id: string, default_quantity?: string|int|float}> $items */
    public function setGroupItems(string $id, array $items): void
    {
        $row = $this->products->find($id) ?? throw new NotFound('The product');
        $row['type'] === 'grouped' || throw new InvalidProduct('Only grouped products contain other products.');

        $clean = [];
        foreach ($items as $item) {
            $member = $this->products->find($item['product_id']) ?? throw new NotFound('One of the grouped products');
            in_array($member['type'], ['simple', 'variable'], true) && $member['id'] !== $id || throw new InvalidProduct('A group can only contain simple or variable products.');
            $clean[$member['id']] = ['product_id' => $member['id'], 'default_quantity' => Input::quantity($item['default_quantity'] ?? 1, 'default quantity') ?? '1'];
            (float) $clean[$member['id']]['default_quantity'] > 0 || throw new InvalidProduct('The default quantity must be greater than zero.');
        }

        $this->products->setGroupItems($id, array_values($clean));
    }

    /** @return string the plain preview secret (shown once) */
    public function issuePreview(string $id, int $ttlMinutes = 120, ?string $createdBy = null): string
    {
        $this->products->find($id) ?? throw new NotFound('The product');

        return $this->previews->issue($id, max(5, min($ttlMinutes, 1440)), $createdBy);
    }

    /** @param array<string, mixed> $input */
    private function syncTaxonomy(string $id, array $input): void
    {
        if (array_key_exists('category_ids', $input)) {
            $ids = array_values($input['category_ids'] ?? []);
            $primary = $input['primary_category_id'] ?? null;
            $primary === null || in_array($primary, $ids, true) || throw new InvalidProduct('The primary category must be one of the selected categories.');
            $this->assertCategoriesAndTagsExist($ids, []);
            $this->products->setCategories($id, $ids, $primary);
        }
        if (array_key_exists('tag_ids', $input)) {
            $this->assertCategoriesAndTagsExist([], $input['tag_ids'] ?? []);
            $this->products->setTags($id, array_values($input['tag_ids'] ?? []));
        }
    }

    /** @param list<string> $categoryIds @param list<string> $tagIds */
    private function assertCategoriesAndTagsExist(array $categoryIds, array $tagIds): void
    {
        foreach ($categoryIds as $categoryId) {
            $this->categories->find($categoryId) ?? throw new NotFound('One of the categories');
        }
        foreach ($tagIds as $tagId) {
            $this->tags->find($tagId) ?? throw new NotFound('One of the tags');
        }
    }

    /** @param array<string, array<string, ?string>> $translations */
    private function assertSlugsFree(array $translations, ?string $exceptId = null): void
    {
        foreach ($translations as $locale => $t) {
            ! $this->products->slugTaken($locale, $t['slug'], $exceptId) || throw new SlugTaken($t['slug'], $locale);
        }
    }

    /** A published product must keep satisfying the publishing rules after an edit. */
    private function assertStillPublishable(array $row, array $columns, ?array $translations): void
    {
        if ($row['status'] !== 'published') {
            return;
        }
        $after = array_replace($row, array_intersect_key($columns, array_flip(['sku', 'price'])));
        $after['translations'] = array_replace_recursive($row['translations'], $translations ?? []);

        $this->policy->assertPublishable($this->snapshot($after));
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function snapshot(array $row): array
    {
        $parent = $row['parent_id'] ? $this->products->find($row['parent_id']) : null;

        return [
            'type' => $row['type'], 'sku' => $row['sku'],
            // A variation without its own price inherits the parent's.
            'price' => $row['price'] ?? $parent['price'] ?? null,
            'locales' => Localized::supported(), 'default_locale' => Localized::default(),
            'translations' => $parent['translations'] ?? $row['translations'],
            'published_variations' => $row['type'] === 'variable' ? $this->products->countPublishedVariations($row['id']) : 0,
            'group_items' => count($row['group_items']),
            'variation_attributes' => count(array_filter($row['attributes'], fn ($a) => $a['is_variation'])),
        ];
    }

    private function cleanSku(mixed $sku): ?string
    {
        $sku = $this->clean($sku, 64);

        $sku === null || (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-\/]*$/', $sku) || throw new InvalidProduct('The SKU may only contain letters, digits and . _ - /');

        return $sku;
    }

    private function clean(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);
        mb_strlen($value) <= $max || throw new InvalidProduct("Text longer than {$max} characters.");

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function stockColumns(array $input, bool $partial = false): array
    {
        $out = [];
        foreach (['manage_stock', 'allow_backorder'] as $flag) {
            if (! $partial || array_key_exists($flag, $input)) {
                $out[$flag] = (bool) ($input[$flag] ?? ($flag === 'manage_stock'));
            }
        }
        if (! $partial || array_key_exists('low_stock_threshold', $input)) {
            $out['low_stock_threshold'] = Input::quantity($input['low_stock_threshold'] ?? null, 'low stock threshold');
        }

        return $out;
    }

    private function taxClass(mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }
        $this->products->taxClassExists((int) $id) || throw new InvalidProduct('That tax class does not exist.');

        return (int) $id;
    }
}
