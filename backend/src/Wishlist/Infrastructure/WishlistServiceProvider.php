<?php

declare(strict_types=1);

namespace Cronos\Wishlist\Infrastructure;

use Cronos\Wishlist\Domain\Ports\WishlistRepository;
use Cronos\Wishlist\Infrastructure\Persistence\DatabaseWishlistRepository;
use Illuminate\Support\ServiceProvider;

final class WishlistServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [WishlistRepository::class => DatabaseWishlistRepository::class];
}
