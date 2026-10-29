<?php

declare(strict_types=1);

namespace Cronos\Pricing\Domain\ValueObjects;

final class TaxBreakdown
{
    /** @param list<array{name: string, rate: string, amount: Money}> $lines */
    public function __construct(public readonly Money $net, public readonly array $lines, public readonly Money $tax, public readonly Money $gross) {}
}
