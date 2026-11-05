<?php

declare(strict_types=1);

namespace Cronos\Inventory\Application;

use Cronos\Inventory\Domain\Ports\LabelStyleStore;
use Cronos\Inventory\Domain\Services\LabelStyleRules;

/** The look of the QR labels, editable from the admin, every change kept. */
final class LabelStyles
{
    public function __construct(private readonly LabelStyleStore $store) {}

    /** @return array<string, mixed> */
    public function current(): array
    {
        return LabelStyleRules::merge($this->store->current() ?? [], LabelStyleRules::DEFAULTS);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function update(array $changes, ?string $userId): array
    {
        $style = LabelStyleRules::merge($changes, $this->current());
        $style === $this->current() || $this->store->save($style, $userId);

        return $style;
    }

    /** @return list<array{style: array<string, mixed>, changed_by: ?string, changed_at: string}> */
    public function history(int $limit = 50): array
    {
        return $this->store->history($limit);
    }
}
