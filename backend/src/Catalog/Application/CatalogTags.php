<?php

declare(strict_types=1);

namespace Cronos\Catalog\Application;

/** Names of the cache tags, in one place so readers and invalidators cannot drift apart. */
final class CatalogTags
{
    public const PRODUCTS = 'catalog:products';

    public const LISTS = 'catalog:lists';

    public const TREE = 'catalog:tree';

    public const CATEGORIES = 'catalog:categories';

    public static function product(string $id): string
    {
        return "catalog:product:{$id}";
    }
}
