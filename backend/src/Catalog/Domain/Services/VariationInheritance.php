<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\Services;

/**
 * A variation stores only what differs from its parent: null means "inherit".
 * This resolves the effective values and reports which ones are overridden.
 */
final class VariationInheritance
{
    /** Fields a variation may override. Sales unit, categories and tags always come from the parent. */
    public const OVERRIDABLE = ['price', 'weight', 'dimensions', 'barcode'];

    /**
     * @param  array<string, mixed>  $parent
     * @param  array<string, mixed>  $variation
     * @return array{values: array<string, mixed>, overridden: list<string>}
     */
    public function resolve(array $parent, array $variation): array
    {
        $values = [];
        $overridden = [];

        foreach (self::OVERRIDABLE as $field) {
            $own = $variation[$field] ?? null;

            if ($own !== null && $own !== []) {
                $values[$field] = $own;
                $overridden[] = $field;
            } else {
                $values[$field] = $parent[$field] ?? null;
            }
        }

        return ['values' => $values, 'overridden' => $overridden];
    }
}
