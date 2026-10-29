<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Ports;

/**
 * @phpstan-type Translations array<string, array{name: string, slug: string, description?: ?string, meta_title?: ?string, meta_description?: ?string}>
 * @phpstan-type CategoryRow array{id: string, parent_id: ?string, path: string, depth: int, position: int, is_active: bool, image_media_id: ?string, default_tax_class_id: ?int, translations: Translations}
 */
interface CategoryRepository
{
    /** @return CategoryRow|null */
    public function find(string $id): ?array;

    /** Whole tree flattened and ordered by path, so parents always precede their children. @return list<CategoryRow> */
    public function all(): array;

    /** @param Translations $translations @return string new id */
    public function taxClassExists(int $id): bool;

    public function create(?string $parentId, int $position, bool $isActive, ?string $imageMediaId, array $translations): string;

    /** @param array{position?: int, is_active?: bool, image_media_id?: ?string, default_tax_class_id?: ?int} $attributes @param Translations|null $translations */
    public function update(string $id, array $attributes, ?array $translations): void;

    /** Re-parents the category and rewrites the path of its whole subtree. */
    public function move(string $id, ?string $newParentId, int $position): void;

    public function isDescendantOf(string $candidateId, string $ancestorId): bool;

    public function hasChildren(string $id): bool;

    /** Soft delete: frees the slugs and detaches the products. */
    public function delete(string $id): void;

    public function slugTaken(string $locale, string $slug, ?string $exceptId = null): bool;
}
