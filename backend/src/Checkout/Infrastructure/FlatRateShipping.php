<?php

declare(strict_types=1);

namespace Cronos\Checkout\Infrastructure;

use Brick\Math\BigDecimal;
use Cronos\Checkout\Domain\Ports\ShippingRater;
use Cronos\Markets\Domain\Ports\MarketProfileRepository;

/**
 * Flat fee per market, free above a threshold, edited by the admin in the market's profile (config/checkout.php is only the default
 * for a market that has none). Replace with a carrier rater when real quotes arrive.
 */
final class FlatRateShipping implements ShippingRater
{
    public function __construct(private readonly MarketProfileRepository $profiles) {}

    public function rate(string $marketCode, string $itemsTotal): string
    {
        $profile = $this->profiles->profile($marketCode);
        $fallback = config('checkout.shipping.'.strtoupper($marketCode));
        $flat = $profile['shipping_flat'] ?? $fallback['flat'] ?? null;
        $freeOver = $profile['shipping_free_over'] ?? $fallback['free_over'] ?? null;

        if ($flat === null) {
            return '0.00';
        }

        return $freeOver !== null && BigDecimal::of($itemsTotal)->isGreaterThanOrEqualTo($freeOver) ? '0.00' : (string) BigDecimal::of($flat)->toScale(2);
    }
}
