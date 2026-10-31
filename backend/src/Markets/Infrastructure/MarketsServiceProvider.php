<?php

declare(strict_types=1);

namespace Cronos\Markets\Infrastructure;

use Cronos\Markets\Domain\Ports\MarketProfileRepository;
use Cronos\Markets\Infrastructure\Persistence\DatabaseMarketProfileRepository;
use Illuminate\Support\ServiceProvider;

final class MarketsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [MarketProfileRepository::class => DatabaseMarketProfileRepository::class];
}
