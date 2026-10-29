<?php

declare(strict_types=1);

namespace Cronos\Pricing\Domain\Ports;

/** Read-mostly reference data (a handful of rows) served from a permanent tagged cache. */
interface PricingCatalog
{
    /** @return array{currencies: list<array<string, mixed>>, markets: list<array<string, mixed>>, tax_classes: list<array<string, mixed>>, tax_rates: list<array<string, mixed>>} */
    public function snapshot(): array;

    public function forget(): void;
}
