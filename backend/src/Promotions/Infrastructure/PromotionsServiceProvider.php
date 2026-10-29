<?php

declare(strict_types=1);

namespace Cronos\Promotions\Infrastructure;

use Cronos\Promotions\Domain\Ports\CouponRepository;
use Cronos\Promotions\Domain\Ports\PromotionRepository;
use Cronos\Promotions\Infrastructure\Persistence\EloquentCouponRepository;
use Cronos\Promotions\Infrastructure\Persistence\EloquentPromotionRepository;
use Illuminate\Support\ServiceProvider;

final class PromotionsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        CouponRepository::class => EloquentCouponRepository::class,
        PromotionRepository::class => EloquentPromotionRepository::class,
    ];
}
