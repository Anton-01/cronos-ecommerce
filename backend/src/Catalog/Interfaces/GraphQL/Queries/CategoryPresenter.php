<?php

declare(strict_types=1);

namespace Cronos\Catalog\Interfaces\GraphQL\Queries;

final class CategoryPresenter
{
    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function present(array $row): array
    {
        return [
            'id' => $row['id'], 'parentId' => $row['parent_id'], 'path' => $row['path'], 'depth' => $row['depth'], 'position' => $row['position'],
            'isActive' => $row['is_active'], 'imageMediaId' => $row['image_media_id'], 'taxClassId' => $row['default_tax_class_id'],
            'translations' => array_values(array_map(fn (string $locale, array $t) => [
                'locale' => $locale, 'name' => $t['name'], 'slug' => $t['slug'], 'description' => $t['description'] ?? null,
                'metaTitle' => $t['meta_title'] ?? null, 'metaDescription' => $t['meta_description'] ?? null,
            ], array_keys($row['translations']), array_values($row['translations']))),
        ];
    }
}
