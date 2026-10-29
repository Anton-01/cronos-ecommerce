<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Services;

/** Identity of a variation: the set of attribute values it represents, independent of order. */
final class VariationCombination
{
    /** @param list<string> $attributeValueIds */
    public static function hash(array $attributeValueIds): string
    {
        $ids = array_values(array_unique($attributeValueIds));
        sort($ids, SORT_STRING);

        return sha1(implode('|', $ids));
    }

    /**
     * Every combination of the given option lists (cartesian product).
     *
     * @param  array<string, list<string>>  $optionsByAttribute  attribute id => value ids
     * @return list<list<string>>
     */
    public static function cartesian(array $optionsByAttribute): array
    {
        $result = [[]];

        foreach ($optionsByAttribute as $values) {
            $next = [];
            foreach ($result as $partial) {
                foreach ($values as $value) {
                    $next[] = [...$partial, $value];
                }
            }
            $result = $next;
        }

        return $optionsByAttribute === [] ? [] : $result;
    }
}
