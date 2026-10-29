<?php

declare(strict_types=1);

namespace Cronos\Pricing\Infrastructure\Adapters;

use Cronos\Pricing\Domain\Ports\CurrencyStore;
use Cronos\Pricing\Domain\Ports\MarketStore;
use Cronos\Pricing\Domain\Ports\PricingCatalog;
use Cronos\Pricing\Domain\Ports\TaxStore;
use Illuminate\Support\Facades\Cache;

/** Currencies, markets and tax rules are a few dozen rows read on every price: one permanent snapshot, dropped on any write. */
final class CachedPricingCatalog implements PricingCatalog
{
    private const TAG = 'pricing';

    private const KEY = 'pricing:snapshot';

    public function __construct(private readonly CurrencyStore $currencies, private readonly MarketStore $markets, private readonly TaxStore $taxes) {}

    public function snapshot(): array
    {
        return Cache::tags([self::TAG])->rememberForever(self::KEY, fn (): array => [
            'currencies' => $this->currencies->all(),
            'markets' => $this->markets->all(),
            'tax_classes' => $this->taxes->classes(),
            'tax_rates' => $this->taxes->rates(),
        ]);
    }

    public function forget(): void
    {
        Cache::tags([self::TAG])->flush();
    }
}
