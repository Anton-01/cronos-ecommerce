<?php

declare(strict_types=1);

namespace Cronos\Payments\Infrastructure\Jobs;

use Cronos\Payments\Application\OutboxRelay;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Delivers pending outbox messages right after a payment change commits; the scheduler runs the same relay as a safety net. */
final class RelayOutboxJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 30;

    public function __construct()
    {
        $this->onQueue('payments');
    }

    public function handle(OutboxRelay $relay): void
    {
        $relay->run();
    }
}
