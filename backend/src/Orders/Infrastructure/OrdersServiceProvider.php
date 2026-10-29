<?php

declare(strict_types=1);

namespace Cronos\Orders\Infrastructure;

use Cronos\Checkout\Domain\Events\CheckoutPaid;
use Cronos\Orders\Application\Listeners\PlaceOrderOnCheckoutPaid;
use Cronos\Orders\Application\Listeners\SyncOrderWithPayment;
use Cronos\Orders\Domain\Ports\OrderRepository;
use Cronos\Orders\Infrastructure\Persistence\DatabaseOrderRepository;
use Cronos\Payments\Domain\Events\PaymentRefunded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class OrdersServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [OrderRepository::class => DatabaseOrderRepository::class];

    public function boot(): void
    {
        Event::listen(CheckoutPaid::class, [PlaceOrderOnCheckoutPaid::class, 'handle']);
        Event::listen(PaymentRefunded::class, [SyncOrderWithPayment::class, 'handle']);
    }
}
