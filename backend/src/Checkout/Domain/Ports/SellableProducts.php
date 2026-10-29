<?php

declare(strict_types=1);

namespace Cronos\Checkout\Domain\Ports;

use Cronos\Catalog\Domain\ValueObjects\SalesUnit;

/** What the cart needs to know about the catalogue: can it be bought, and how is it sold. */
interface SellableProducts
{
    /**
     * @param  list<string>  $ids
     * @return array<string, array{id: string, sku: ?string, name: string, sales: SalesUnit}> only products that exist and are published and buyable
     */
    public function find(array $ids): array;

    /** ISO country the market sells to (a MX checkout ships to MX). */
    public function marketCountry(string $marketCode): ?string;
}
