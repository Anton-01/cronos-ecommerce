<?php

declare(strict_types=1);

namespace Cronos\Checkout\Infrastructure;

use Brick\Math\BigDecimal;
use Cronos\Checkout\Domain\Ports\ShippingRater;

/** Placeholder until carriers arrive: a flat fee per market, free above a threshold (config/checkout.php). */
final class FlatRateShipping implements ShippingRater
{
    public function rate(string $marketCode, string $itemsTotal): string
    {
        $rule = config('checkout.shipping.'.strtoupper($marketCode));
        if ($rule === null) {
            return '0.00';
        }

        return BigDecimal::of($itemsTotal)->isGreaterThanOrEqualTo($rule['free_over']) ? '0.00' : (string) BigDecimal::of($rule['flat'])->toScale(2);
    }
}
