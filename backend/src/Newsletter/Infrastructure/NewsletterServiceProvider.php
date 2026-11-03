<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Infrastructure;

use Cronos\Newsletter\Domain\Ports\SubscriberRepository;
use Cronos\Newsletter\Infrastructure\Persistence\DatabaseSubscriberRepository;
use Illuminate\Support\ServiceProvider;

final class NewsletterServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [SubscriberRepository::class => DatabaseSubscriberRepository::class];
}
