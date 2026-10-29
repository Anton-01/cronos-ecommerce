<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Ports;

/**
 * @phpstan-type ProductRow array{
 *     id: string, parent_id: ?string, type: string, status: string, sku: ?string, barcode: ?string,
 *     price: ?string, weight: ?string, dimensions: ?array<string, mixed>, sales: array<string, mixed>,
 *     manage_stock: bool, allow_backorder: bool, low_stock_threshold: ?string, stock_quantity: string,
 *     is_customizable: bool, position: int, published_at: ?string,
 *     translations: array<string, array{name?: ?string, slug?: ?string, short_description?: ?string, description?: ?string, meta_title?: ?string, meta_description?: ?string}>,
 *     category_ids: list<string>, primary_category_id: ?string, tag_ids: list<string>,
 *     attributes: list<array{attribute_id: string, is_variation: bool, is_visible: bool, position: int}>,
 *     value_ids: list<string>, group_items: list<array{product_id: string, default_quantity: string, position: int}>
 * }
 */
interface ProductRepository
{
    /** @return ProductRow|null */
    public function find(string $id): ?array;

    /** @return list<ProductRow> */
    public function variationsOf(string $parentId): array;

    /** @param array<string, mixed> $row columns of the products table @param array<string, array<string, ?string>> $translations @return string id */
    public function create(array $row, array $translations): string;

    /** @param array<string, mixed> $columns */
    public function update(string $id, array $columns): void;

    /** @param array<string, array<string, ?string>> $translations replaces the given locales only */
    public function saveTranslations(string $id, array $translations): void;

    /** Soft delete the product (and its variations); frees SKU and slugs. */
    public function delete(string $id): void;

    public function skuTaken(string $sku, ?string $exceptId = null): bool;

    public function slugTaken(string $locale, string $slug, ?string $exceptId = null): bool;

    /** @param list<string> $categoryIds */
    public function setCategories(string $id, array $categoryIds, ?string $primaryId): void;

    /** @param list<string> $tagIds */
    public function setTags(string $id, array $tagIds): void;

    /** @param list<array{attribute_id: string, is_variation: bool, is_visible: bool}> $attributes */
    public function setAttributes(string $id, array $attributes): void;

    /** @param list<string> $valueIds */
    public function setValues(string $id, array $valueIds): void;

    /** @param list<array{product_id: string, default_quantity: string}> $items */
    public function setGroupItems(string $id, array $items): void;

    /** @param list<string> $valueIds @return string id of the new variation */
    public function createVariation(string $parentId, array $row, array $valueIds, string $combinationHash): string;

    public function combinationExists(string $parentId, string $combinationHash, ?string $exceptId = null): bool;

    public function existsAndLive(string $id): bool;

    /** @param list<string> $ids @return list<string> ids that exist and are not deleted */
    public function liveIds(array $ids): array;

    public function nextVariationPosition(string $parentId): int;

    public function countPublishedVariations(string $parentId): int;

    public function markPublished(string $id, string $status): void;
}
