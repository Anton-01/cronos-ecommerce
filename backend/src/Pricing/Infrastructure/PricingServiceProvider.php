<?php

declare(strict_types=1);

namespace Cronos\Pricing\Infrastructure;

use Cronos\Pricing\Domain\Ports\CurrencyStore;
use Cronos\Pricing\Domain\Ports\ExchangeRateProvider;
use Cronos\Pricing\Domain\Ports\MarketStore;
use Cronos\Pricing\Domain\Ports\PricingCatalog;
use Cronos\Pricing\Domain\Ports\ProductPriceStore;
use Cronos\Pricing\Domain\Ports\TaxStore;
use Cronos\Pricing\Infrastructure\Adapters\CachedPricingCatalog;
use Cronos\Pricing\Infrastructure\Adapters\HttpExchangeRateProvider;
use Cronos\Pricing\Infrastructure\Console\UpdateExchangeRatesCommand;
use Cronos\Pricing\Infrastructure\Persistence\Eloquent\CurrencyModel;
use Cronos\Pricing\Infrastructure\Persistence\Eloquent\MarketModel;
use Cronos\Pricing\Infrastructure\Persistence\Eloquent\TaxClassModel;
use Cronos\Pricing\Infrastructure\Persistence\Eloquent\TaxRateModel;
use Cronos\Pricing\Infrastructure\Persistence\EloquentCurrencyStore;
use Cronos\Pricing\Infrastructure\Persistence\EloquentMarketStore;
use Cronos\Pricing\Infrastructure\Persistence\EloquentProductPriceStore;
use Cronos\Pricing\Infrastructure\Persistence\EloquentTaxStore;
use Cronos\Pricing\Infrastructure\Persistence\PricingObserver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class PricingServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        CurrencyStore::class => EloquentCurrencyStore::class,
        MarketStore::class => EloquentMarketStore::class,
        TaxStore::class => EloquentTaxStore::class,
        ProductPriceStore::class => EloquentProductPriceStore::class,
        PricingCatalog::class => CachedPricingCatalog::class,
        ExchangeRateProvider::class => HttpExchangeRateProvider::class,
    ];

    public function boot(): void
    {
        foreach ([CurrencyModel::class, MarketModel::class, TaxClassModel::class, TaxRateModel::class] as $model) {
            $model::observe(PricingObserver::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([UpdateExchangeRatesCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('pricing:update-rates')->dailyAt('03:15')->withoutOverlapping()->onOneServer();
        });
    }
}
