<?php

declare(strict_types=1);

namespace Cronos\Catalog\Application;

use Cronos\Catalog\Domain\Exceptions\CategoryCycle;
use Cronos\Catalog\Domain\Exceptions\CategoryNotEmpty;
use Cronos\Catalog\Domain\Exceptions\CategoryTooDeep;
use Cronos\Catalog\Domain\Exceptions\NotFound;
use Cronos\Catalog\Domain\Exceptions\SlugTaken;
use Cronos\Catalog\Domain\Ports\CategoryRepository;

final class CategoryManager
{
    public const MAX_DEPTH = 5;

    private const TEXT = ['description', 'meta_title', 'meta_description'];

    public function __construct(private readonly CategoryRepository $categories) {}

    /** @param array{parent_id?: ?string, position?: int, is_active?: bool, image_media_id?: ?string, translations: array<string, array<string, mixed>>} $input */
    public function create(array $input): string
    {
        $parentId = $input['parent_id'] ?? null;
        $parent = $parentId ? ($this->categories->find($parentId) ?? throw new NotFound('The parent category')) : null;
        ($parent['depth'] ?? 0) + 1 <= self::MAX_DEPTH || throw new CategoryTooDeep(self::MAX_DEPTH);

        $translations = Localized::normalise($input['translations'], self::TEXT);
        $this->assertSlugsFree($translations);

        $taxClassId = $this->taxClass($input['default_tax_class_id'] ?? null);
        $id = $this->categories->create($parentId, (int) ($input['position'] ?? 0), (bool) ($input['is_active'] ?? true), $input['image_media_id'] ?? null, $translations);
        $taxClassId === null || $this->categories->update($id, ['default_tax_class_id' => $taxClassId], null);

        return $id;
    }

    /** @param array{position?: int, is_active?: bool, image_media_id?: ?string, translations?: array<string, array<string, mixed>>} $input */
    public function update(string $id, array $input): void
    {
        $this->categories->find($id) ?? throw new NotFound('The category');

        $translations = isset($input['translations']) ? Localized::normalise($input['translations'], self::TEXT) : null;
        $translations === null || $this->assertSlugsFree($translations, $id);

        $attributes = array_intersect_key($input, array_flip(['position', 'is_active', 'image_media_id', 'default_tax_class_id']));
        array_key_exists('default_tax_class_id', $attributes) && $attributes['default_tax_class_id'] = $this->taxClass($attributes['default_tax_class_id']);

        $this->categories->update($id, $attributes, $translations);
    }

    public function move(string $id, ?string $newParentId, int $position = 0): void
    {
        $node = $this->categories->find($id) ?? throw new NotFound('The category');
        $parent = $newParentId ? ($this->categories->find($newParentId) ?? throw new NotFound('The parent category')) : null;

        // Moving a category under itself or its own descendants would orphan the whole branch.
        ! $newParentId || ! $this->categories->isDescendantOf($newParentId, $id) || throw new CategoryCycle;

        $subtreeHeight = 0;
        foreach ($this->categories->all() as $row) {
            if (str_starts_with($row['path'].'.', $node['path'].'.')) {
                $subtreeHeight = max($subtreeHeight, $row['depth'] - $node['depth']);
            }
        }
        ($parent['depth'] ?? 0) + 1 + $subtreeHeight <= self::MAX_DEPTH || throw new CategoryTooDeep(self::MAX_DEPTH);

        $this->categories->move($id, $newParentId, $position);
    }

    public function delete(string $id): void
    {
        $this->categories->find($id) ?? throw new NotFound('The category');
        ! $this->categories->hasChildren($id) || throw new CategoryNotEmpty;

        $this->categories->delete($id);
    }

    /** @param array<string, array<string, ?string>> $translations */
    private function assertSlugsFree(array $translations, ?string $exceptId = null): void
    {
        foreach ($translations as $locale => $t) {
            ! $this->categories->slugTaken($locale, $t['slug'], $exceptId) || throw new SlugTaken($t['slug'], $locale);
        }
    }

    private function taxClass(mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }
        $this->categories->taxClassExists((int) $id) || throw new NotFound('The tax class');

        return (int) $id;
    }
}
