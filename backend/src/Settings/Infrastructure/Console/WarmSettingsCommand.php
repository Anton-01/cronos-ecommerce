<?php

declare(strict_types=1);

namespace Cronos\Settings\Infrastructure\Console;

use Cronos\Settings\Application\SettingsSnapshot;
use Illuminate\Console\Command;

final class WarmSettingsCommand extends Command
{
    protected $signature = 'settings:warm';

    protected $description = 'Rebuild the settings snapshot in Redis (run on every deploy)';

    public function handle(SettingsSnapshot $snapshot): int
    {
        $snapshot->refresh();
        $this->info('Settings snapshot rebuilt ('.count($snapshot->get()).' keys).');

        return self::SUCCESS;
    }
}
