<?php

declare(strict_types=1);

namespace Cronos\Checkout\Infrastructure;

use Cronos\Checkout\Application\Listeners\NoteReviewOnCheckout;
use Cronos\Checkout\Application\Listeners\SettleCheckoutOnPayment;
use Cronos\Checkout\Domain\Ports\CartRepository;
use Cronos\Checkout\Domain\Ports\CheckoutRepository;
use Cronos\Checkout\Domain\Ports\SellableProducts;
use Cronos\Checkout\Domain\Ports\ShippingRater;
use Cronos\Checkout\Infrastructure\Console\ExpireCheckoutsCommand;
use Cronos\Checkout\Infrastructure\Persistence\DatabaseCartRepository;
use Cronos\Checkout\Infrastructure\Persistence\DatabaseCheckoutRepository;
use Cronos\Checkout\Infrastructure\Persistence\DatabaseSellableProducts;
use Cronos\Payments\Domain\Events\PaymentCaptured;
use Cronos\Payments\Domain\Events\PaymentNeedsReview;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class CheckoutServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        CartRepository::class => DatabaseCartRepository::class,
        CheckoutRepository::class => DatabaseCheckoutRepository::class,
        SellableProducts::class => DatabaseSellableProducts::class,
        ShippingRater::class => FlatRateShipping::class,
    ];

    public function boot(): void
    {
        Event::listen(PaymentCaptured::class, [SettleCheckoutOnPayment::class, 'handle']);
        Event::listen(PaymentNeedsReview::class, [NoteReviewOnCheckout::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireCheckoutsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('checkout:expire')->everyMinute()->withoutOverlapping()->onOneServer();
        });
    }
}
