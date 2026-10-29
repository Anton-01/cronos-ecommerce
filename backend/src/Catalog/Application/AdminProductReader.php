<?php

declare(strict_types=1);

namespace Cronos\Catalog\Application;

use Cronos\Catalog\Domain\Exceptions\NotFound;
use Cronos\Catalog\Domain\Ports\ProductGallery;
use Cronos\Catalog\Domain\Ports\ProductRepository;
use Cronos\Catalog\Domain\Services\PublishingPolicy;
use Cronos\Catalog\Domain\Services\VariationInheritance;

/** Everything the back-office editor needs to show one product, in one array. */
final class AdminProductReader
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductGallery $gallery,
        private readonly ProductManager $manager,
        private readonly PublishingPolicy $policy,
    ) {}

    /** @return array<string, mixed> */
    public function product(string $id): array
    {
        $row = $this->products->find($id) ?? throw new NotFound('The product');
        $inheritance = new VariationInheritance;
        $parent = ['price' => $row['price'], 'weight' => $row['weight'], 'dimensions' => $row['dimensions'], 'barcode' => $row['barcode']];

        $variations = array_map(function (array $v) use ($inheritance, $parent) {
            $resolved = $inheritance->resolve($parent, ['price' => $v['price'], 'weight' => $v['weight'], 'dimensions' => $v['dimensions'], 'barcode' => $v['barcode']]);

            return [
                'id' => $v['id'], 'sku' => $v['sku'], 'status' => $v['status'], 'barcode' => $v['barcode'], 'position' => $v['position'],
                'price' => $v['price'], 'effectivePrice' => $resolved['values']['price'], 'weight' => $v['weight'], 'effectiveWeight' => $resolved['values']['weight'],
                'dimensions' => $v['dimensions'], 'overridden' => $resolved['overridden'], 'valueIds' => $v['value_ids'],
                'stockQuantity' => $v['stock_quantity'], 'publishProblems' => $this->policy->problems($this->manager->snapshot($v)),
            ];
        }, $row['type'] === 'variable' ? $this->products->variationsOf($id) : []);

        $names = [];
        foreach ($row['group_items'] as $item) {
            $member = $this->products->find($item['product_id']);
            $names[$item['product_id']] = $member['translations'][Localized::default()]['name'] ?? $member['sku'] ?? $item['product_id'];
        }

        return [
            'id' => $row['id'], 'parentId' => $row['parent_id'], 'type' => $row['type'], 'status' => $row['status'], 'sku' => $row['sku'], 'barcode' => $row['barcode'], 'taxClassId' => $row['tax_class_id'],
            'price' => $row['price'], 'weight' => $row['weight'], 'dimensions' => $row['dimensions'],
            'sales' => [
                'unit' => $row['sales']['sell_unit'], 'step' => $row['sales']['quantity_step'], 'min' => $row['sales']['min_quantity'], 'max' => $row['sales']['max_quantity'],
                'sellByCut' => (bool) $row['sales']['sell_by_cut'], 'sellByRoll' => (bool) $row['sales']['sell_by_roll'],
                'rollLength' => $row['sales']['roll_length'], 'rollPrice' => $row['sales']['roll_price'],
            ],
            'manageStock' => $row['manage_stock'], 'allowBackorder' => $row['allow_backorder'], 'lowStockThreshold' => $row['low_stock_threshold'], 'stockQuantity' => $row['stock_quantity'],
            'isCustomizable' => $row['is_customizable'], 'position' => $row['position'], 'publishedAt' => $row['published_at'],
            'translations' => array_values(array_map(fn (string $locale, array $t) => [
                'locale' => $locale, 'name' => $t['name'] ?? null, 'slug' => $t['slug'] ?? null, 'shortDescription' => $t['short_description'] ?? null,
                'description' => $t['description'] ?? null, 'metaTitle' => $t['meta_title'] ?? null, 'metaDescription' => $t['meta_description'] ?? null,
            ], array_keys($row['translations']), array_values($row['translations']))),
            'categoryIds' => $row['category_ids'], 'primaryCategoryId' => $row['primary_category_id'], 'tagIds' => $row['tag_ids'],
            'attributes' => array_map(fn (array $a) => ['attributeId' => $a['attribute_id'], 'isVariation' => $a['is_variation'], 'isVisible' => $a['is_visible']], $row['attributes']),
            'offeredValueIds' => $row['value_ids'],
            'groupItems' => array_map(fn (array $g) => ['productId' => $g['product_id'], 'name' => $names[$g['product_id']], 'defaultQuantity' => $g['default_quantity']], $row['group_items']),
            'variations' => $variations,
            'gallery' => $this->gallery->list($id),
            'publishProblems' => $this->policy->problems($this->manager->snapshot($row)),
        ];
    }
}
