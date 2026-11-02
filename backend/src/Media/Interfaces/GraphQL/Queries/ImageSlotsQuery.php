<?php

declare(strict_types=1);

namespace Cronos\Media\Interfaces\GraphQL\Queries;

use Cronos\Media\Application\ImageSlots;

final class ImageSlotsQuery
{
    public function __construct(private readonly ImageSlots $slots) {}

    /** @return list<array<string, mixed>> */
    public function __invoke(): array
    {
        $out = [];
        foreach ($this->slots->all() as $slot => $s) {
            $out[] = ['slot' => $slot, 'label' => $s['label'], 'hint' => $s['hint'], 'ratio' => $s['ratio'], 'recommendedWidth' => $s['recommended'][0], 'recommendedHeight' => $s['recommended'][1],
                'minWidth' => $s['min'][0], 'minHeight' => $s['min'][1], 'widths' => $s['widths'], 'focal' => $s['focal'], 'svg' => $s['svg']];
        }

        return $out;
    }
}
