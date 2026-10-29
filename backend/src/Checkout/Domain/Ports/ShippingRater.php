<?php

declare(strict_types=1);

namespace Cronos\Checkout\Domain\Ports;

interface ShippingRater
{
    /** Shipping for a basket worth $itemsTotal (after item discounts) in the market currency. */
    public function rate(string $marketCode, string $itemsTotal): string;
}
