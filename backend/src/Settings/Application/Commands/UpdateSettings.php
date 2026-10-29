<?php

declare(strict_types=1);

namespace Cronos\Settings\Application\Commands;

final readonly class UpdateSettings
{
    /** @param list<array{group: string, key: string, value?: mixed, translations?: array<string, ?string>}> $changes */
    public function __construct(public array $changes, public ?string $updatedBy) {}
}
