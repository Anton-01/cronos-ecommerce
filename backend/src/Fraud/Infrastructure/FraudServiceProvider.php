<?php

declare(strict_types=1);

namespace Cronos\Fraud\Infrastructure;

use Cronos\Fraud\Domain\Ports\FraudRepository;
use Cronos\Fraud\Domain\Ports\FraudSignals;
use Cronos\Fraud\Infrastructure\Adapters\CacheFraudSignals;
use Cronos\Fraud\Infrastructure\Persistence\EloquentFraudRepository;
use Illuminate\Support\ServiceProvider;

final class FraudServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        FraudRepository::class => EloquentFraudRepository::class,
        FraudSignals::class => CacheFraudSignals::class,
    ];
}
