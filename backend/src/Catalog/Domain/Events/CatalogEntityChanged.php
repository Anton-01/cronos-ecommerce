<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Events;

/** Something in the catalogue changed: caches that depend on it must be purged. */
final readonly class CatalogEntityChanged
{
    /** @param 'product'|'category'|'tag'|'attribute' $type */
    public function __construct(public string $type, public string $id) {}
}
