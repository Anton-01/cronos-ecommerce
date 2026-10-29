<?php

declare(strict_types=1);

namespace Cronos\Settings\Domain\ValueObjects;

final readonly class StoredSetting
{
    /** @param array<string, ?string> $translations keyed by locale */
    public function __construct(
        public string $group,
        public string $key,
        public mixed $value,
        public array $translations = [],
    ) {}
}
