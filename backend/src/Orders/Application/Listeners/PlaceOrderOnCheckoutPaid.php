<?php

declare(strict_types=1);

namespace Cronos\Orders\Application\Listeners;

use Cronos\Checkout\Domain\Events\CheckoutPaid;
use Cronos\Orders\Application\OrderPlacer;

final class PlaceOrderOnCheckoutPaid
{
    public function __construct(private readonly OrderPlacer $placer) {}

    public function handle(CheckoutPaid $e): void
    {
        $this->placer->fromCheckout($e->checkoutId);
    }
}
