<?php

declare(strict_types=1);

namespace Cronos\Contact\Infrastructure;

use Cronos\Contact\Domain\Ports\ContactMessageRepository;
use Cronos\Contact\Domain\Ports\HumanVerifier;
use Cronos\Contact\Infrastructure\Persistence\DatabaseContactMessageRepository;
use Illuminate\Support\ServiceProvider;

final class ContactServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ContactMessageRepository::class => DatabaseContactMessageRepository::class,
        HumanVerifier::class => TurnstileVerifier::class,
    ];
}
