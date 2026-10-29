<?php

declare(strict_types=1);

namespace Cronos\Settings\Domain\Ports;

interface MediaLookup
{
    /** True when the id is a live image that can be used as a logo/favicon. */
    public function isUsableImage(string $mediaId): bool;

    /** @param list<string> $ids @return array<string, string> media id => public URL (missing ids are omitted) */
    public function urls(array $ids): array;
}
