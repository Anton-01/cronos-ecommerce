<?php

declare(strict_types=1);

namespace Cronos\Payments\Infrastructure\Console;

use Cronos\Payments\Application\OutboxRelay;
use Illuminate\Console\Command;

final class RelayOutboxCommand extends Command
{
    protected $signature = 'outbox:relay';

    protected $description = 'Deliver pending outbox messages';

    public function handle(OutboxRelay $relay): int
    {
        $this->info('Delivered: '.$relay->run());

        return self::SUCCESS;
    }
}
