<?php

declare(strict_types=1);

namespace Cronos\Shipping\Infrastructure;

use Cronos\Shipping\Domain\Ports\ShipmentRepository;
use Cronos\Shipping\Infrastructure\Persistence\DatabaseShipmentRepository;
use Illuminate\Support\ServiceProvider;

final class ShippingServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [ShipmentRepository::class => DatabaseShipmentRepository::class];
}
