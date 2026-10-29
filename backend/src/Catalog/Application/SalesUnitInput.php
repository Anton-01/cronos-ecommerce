<?php

declare(strict_types=1);

namespace Cronos\Catalog\Application;

/** Converts stored columns back into the input shape of {@see Input::salesUnit()}. */
final class SalesUnitInput
{
    /** @param array<string, mixed> $columns @return array<string, mixed> */
    public static function fromColumns(array $columns): array
    {
        return [
            'unit' => $columns['sell_unit'], 'step' => $columns['quantity_step'], 'min' => $columns['min_quantity'], 'max' => $columns['max_quantity'] ?? null,
            'sell_by_cut' => (bool) $columns['sell_by_cut'], 'sell_by_roll' => (bool) $columns['sell_by_roll'],
            'roll_length' => $columns['roll_length'] ?? null, 'roll_price' => $columns['roll_price'] ?? null,
        ];
    }
}
